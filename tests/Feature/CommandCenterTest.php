<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\SyncedEvent;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 (Session Command Center): polling-based live summary. Every
 * number must trace to the same formulas already used elsewhere
 * (ReportService) — these tests verify no second calculation was invented,
 * plus broad read access and the offline-sync "attention" surfacing.
 */
class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(?string $name = null): Department
    {
        $name = $name ?? 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function assignment(DutySession $session, User $uploader, Department $dept, array $overrides = []): DutyAssignment
    {
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $uploader->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Person']);

        return DutyAssignment::create(array_merge([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ], $overrides));
    }

    public function test_admin_operator_and_viewer_can_all_view_command_center(): void
    {
        $session = $this->activeSession();

        foreach (['admin', 'operator', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('sessions.command-center', $session))->assertOk();
        }
    }

    public function test_counters_match_present_over_scheduled_and_exclude_extra(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department();
        $a1 = $this->assignment($session, $admin, $dept);
        $a2 = $this->assignment($session, $admin, $dept);
        $service = app(AttendanceService::class);
        $service->markPresent($session, $a1->id, $admin);

        // Add an Extra Present too — must never affect the rate.
        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Extra']);
        $service->markExtraPresentKnown($session, $extraKg, $dept, 'Male', $admin);

        $response = $this->actingAs($admin)->getJson(route('sessions.command-center.data', $session));

        $response->assertOk();
        $response->assertJsonPath('counters.scheduled', 2);
        $response->assertJsonPath('counters.present', 1);
        $response->assertJsonPath('counters.pending', 1);
        $response->assertJsonPath('counters.rate', 50); // 1/2, not 1/3 with extra folded in
        $this->assertSame(1, ExtraPresent::count());
    }

    public function test_department_rows_present_in_response(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department('SECURITY');
        $this->assignment($session, $admin, $dept);

        $response = $this->actingAs($admin)->getJson(route('sessions.command-center.data', $session));

        $response->assertJsonFragment(['department_name' => 'SECURITY']);
    }

    public function test_recent_activity_is_most_recent_first(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department();
        $a1 = $this->assignment($session, $admin, $dept);
        $a2 = $this->assignment($session, $admin, $dept);
        $service = app(AttendanceService::class);

        $service->markPresent($session, $a1->id, $admin);
        AttendanceEvent::where('duty_assignment_id', $a1->id)->update(['performed_at' => now()->subMinutes(5)]);
        $service->markAbsent($session, $a2->id, $admin);

        $response = $this->actingAs($admin)->getJson(route('sessions.command-center.data', $session));

        $activity = $response->json('recentActivity');
        $this->assertCount(2, $activity);
        $this->assertSame('Absent', explode(' —', $activity[0]['description'])[0]); // the later event (Absent) comes first
    }

    public function test_attention_reflects_offline_sync_issues_for_this_session_only(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $otherSession = $this->activeSession();

        SyncedEvent::create([
            'event_id' => (string) Str::uuid(), 'duty_session_id' => $session->id,
            'action' => 'extra_present', 'last_result' => 'conflict', 'attempt_count' => 1,
            'first_received_at' => now(), 'last_attempted_at' => now(), 'synced_by_user_id' => $admin->id,
        ]);
        SyncedEvent::create([
            'event_id' => (string) Str::uuid(), 'duty_session_id' => $session->id,
            'action' => 'present', 'last_result' => 'accepted', 'attempt_count' => 1,
            'first_received_at' => now(), 'last_attempted_at' => now(), 'synced_by_user_id' => $admin->id,
        ]);
        SyncedEvent::create([
            'event_id' => (string) Str::uuid(), 'duty_session_id' => $otherSession->id,
            'action' => 'present', 'last_result' => 'rejected', 'attempt_count' => 1,
            'first_received_at' => now(), 'last_attempted_at' => now(), 'synced_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('sessions.command-center.data', $session));

        $response->assertJsonPath('attention.count', 1); // only the conflict for THIS session, not the other session's rejected, not the accepted one
        $response->assertJsonPath('attention.conflict', 1);
        $response->assertJsonPath('attention.rejected', 0);
    }

    public function test_closed_session_command_center_remains_viewable_read_only(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'Closed', 'date' => now()->format('Y-m-d'), 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $admin->id]);

        $this->actingAs($admin)->get(route('sessions.command-center', $session))->assertOk();
    }
}
