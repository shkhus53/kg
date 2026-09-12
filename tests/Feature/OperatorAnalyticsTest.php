<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 (Operator Analytics): operational workload counts per admin/
 * operator account — not a leaderboard (no ranking exposed to non-admins,
 * no gamification). Every number traces to AttendanceEvent/ExtraPresent.
 */
class OperatorAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'S', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(): Department
    {
        $name = 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function assignment(DutySession $session, ImportBatch $batch, Department $dept): DutyAssignment
    {
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Person']);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);
    }

    public function test_only_admin_can_view_operator_analytics(): void
    {
        $session = $this->activeSession();

        $this->actingAs($this->admin())->get(route('analytics.operators'))->assertOk();
        $this->actingAs(User::factory()->operator()->create())->get(route('analytics.operators'))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('analytics.operators'))->assertForbidden();
    }

    public function test_counts_present_absent_and_corrections_per_operator(): void
    {
        $admin = $this->admin();
        $operatorA = User::factory()->operator()->create(['name' => 'Operator A']);
        $operatorB = User::factory()->operator()->create(['name' => 'Operator B']);
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $service = app(AttendanceService::class);

        $a1 = $this->assignment($session, $batch, $dept);
        $a2 = $this->assignment($session, $batch, $dept);
        $a3 = $this->assignment($session, $batch, $dept);

        $service->markPresent($session, $a1->id, $operatorA); // plain present
        $service->markAbsent($session, $a2->id, $operatorA);
        $service->markPresent($session, $a2->id, $operatorA); // correction: absent -> present, same operator

        $service->markAbsent($session, $a3->id, $operatorB);

        $response = $this->actingAs($admin)->get(route('analytics.operators'));

        $response->assertOk();
        $response->assertSee('Operator A');
        $response->assertSee('Operator B');

        // Operator A: 3 total actions (present, absent, present-correction), 2 present, 1 absent, 1 correction.
        // Operator B: 1 total action, 0 present, 1 absent, 0 corrections.
        $data = $this->actingAs($admin)->get(route('analytics.operators'))->viewData('operators');
        $rowA = $data->firstWhere('user.name', 'Operator A');
        $rowB = $data->firstWhere('user.name', 'Operator B');

        $this->assertSame(3, $rowA['total_actions']);
        $this->assertSame(2, $rowA['present_count']);
        $this->assertSame(1, $rowA['absent_count']);
        $this->assertSame(1, $rowA['corrections_count']);

        $this->assertSame(1, $rowB['total_actions']);
        $this->assertSame(0, $rowB['present_count']);
        $this->assertSame(1, $rowB['absent_count']);
        $this->assertSame(0, $rowB['corrections_count']);
    }

    public function test_extra_present_counted_separately_from_scheduled_actions(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->operator()->create(['name' => 'Extra Marker']);
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $this->assignment($session, $batch, $dept); // gives the session a department in scope

        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Extra Person']);
        app(AttendanceService::class)->markExtraPresentKnown($session, $kg, $dept, 'Male', $operator);

        $data = $this->actingAs($admin)->get(route('analytics.operators'))->viewData('operators');
        $row = $data->firstWhere('user.name', 'Extra Marker');

        $this->assertSame(0, $row['total_actions']); // no scheduled attendance events
        $this->assertSame(1, $row['extra_count']);
        $this->assertNotNull($row['last_activity']);
    }

    public function test_operators_with_zero_activity_are_excluded(): void
    {
        $admin = $this->admin();
        User::factory()->operator()->create(['name' => 'Never Active']);

        $data = $this->actingAs($admin)->get(route('analytics.operators'))->viewData('operators');

        $this->assertNull($data->firstWhere('user.name', 'Never Active'));
    }

    public function test_date_range_filters_out_activity_outside_window(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->operator()->create(['name' => 'Old Activity']);
        $oldSession = DutySession::create(['name' => 'Old', 'date' => '2020-01-01', 'status' => 'active']);
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $oldSession->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $assignment = $this->assignment($oldSession, $batch, $dept);
        app(AttendanceService::class)->markPresent($oldSession, $assignment->id, $operator);

        // Default range is last 30 days — 2020 activity must not appear.
        $data = $this->actingAs($admin)->get(route('analytics.operators'))->viewData('operators');

        $this->assertNull($data->firstWhere('user.name', 'Old Activity'));
    }

    public function test_view_activity_link_routes_to_audit_log_filtered_by_operator(): void
    {
        $admin = $this->admin();
        $operator = User::factory()->operator()->create(['name' => 'Linked Operator']);
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $assignment = $this->assignment($session, $batch, $dept);
        app(AttendanceService::class)->markPresent($session, $assignment->id, $operator);

        $response = $this->actingAs($admin)->get(route('analytics.operators'));

        $response->assertSee(route('audit.index', ['operator_id' => $operator->id]), false);
    }
}
