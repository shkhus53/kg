<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locked transition rules (Phase 10.2):
 *   Pending -> Present   allowed
 *   Pending -> Absent    allowed
 *   Absent  -> Present   allowed (late-arrival correction)
 *   Present -> Absent    blocked
 *   Closed session       blocks every mutation
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function activeSession(): DutySession
    {
        return DutySession::create(['name' => 'Correction Test Session', 'date' => now()->format('Y-m-d'), 'status' => 'active']);
    }

    private function department(): Department
    {
        $name = 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    /** @return array{0: DutySession, 1: DutyAssignment, 2: User} */
    private function pendingAssignment(?DutySession $session = null): array
    {
        $session ??= $this->activeSession();
        $user = $this->admin();
        $dept = $this->department();
        $kg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Correction Person']);
        $batch = ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $user->id,
            'original_filename' => 'test.csv', 'file_type' => 'csv', 'status' => 'completed',
        ]);
        $assignment = DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id,
            'khidmatguzar_id' => $kg->id, 'department_id' => $dept->id,
            'source_row_number' => 2, 'assignment_fingerprint' => hash('sha256', uniqid()),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);

        return [$session, $assignment, $user];
    }

    public function test_pending_to_present_succeeds(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();

        $result = app(AttendanceService::class)->markPresent($session, $assignment->id, $user);

        $this->assertSame('marked', $result['result']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_pending_to_absent_succeeds(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();

        $result = app(AttendanceService::class)->markAbsent($session, $assignment->id, $user);

        $this->assertSame('marked', $result['result']);
        $this->assertSame('absent', $assignment->fresh()->current_status);
    }

    public function test_absent_to_present_succeeds(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $result = $service->markPresent($session, $assignment->id, $user);

        $this->assertSame('corrected', $result['result']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_present_to_absent_is_rejected(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markPresent($session, $assignment->id, $user);
        $result = $service->markAbsent($session, $assignment->id, $user);

        $this->assertSame('already_present', $result['result']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_absent_to_present_correction_does_not_create_another_assignment(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $service->markPresent($session, $assignment->id, $user);

        $this->assertSame(1, DutyAssignment::where('khidmatguzar_id', $assignment->khidmatguzar_id)
            ->where('duty_session_id', $session->id)->count());
    }

    public function test_absent_to_present_correction_does_not_create_extra_present(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $service->markPresent($session, $assignment->id, $user);

        $this->assertSame(0, ExtraPresent::where('khidmatguzar_id', $assignment->khidmatguzar_id)->count());
    }

    public function test_absent_to_present_correction_preserves_the_original_absent_event(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $service->markPresent($session, $assignment->id, $user);

        $events = AttendanceEvent::where('duty_assignment_id', $assignment->id)->orderBy('id')->get();

        $this->assertSame(2, $events->count());
        $this->assertSame('absent', $events[0]->action);
        $this->assertSame('present', $events[1]->action);
    }

    public function test_closed_session_blocks_absent_to_present(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);
        $service->markAbsent($session, $assignment->id, $user);
        $session->update(['status' => 'closed']);

        $result = $service->markPresent($session, $assignment->id, $user);

        $this->assertSame('session_not_active', $result['result']);
        $this->assertSame('absent', $assignment->fresh()->current_status);
    }

    public function test_closed_session_blocks_pending_to_present(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $session->update(['status' => 'closed']);

        $result = app(AttendanceService::class)->markPresent($session, $assignment->id, $user);

        $this->assertSame('session_not_active', $result['result']);
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }

    public function test_closed_session_blocks_pending_to_absent(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $session->update(['status' => 'closed']);

        $result = app(AttendanceService::class)->markAbsent($session, $assignment->id, $user);

        $this->assertSame('session_not_active', $result['result']);
        $this->assertSame('pending', $assignment->fresh()->current_status);
    }

    public function test_counters_reconcile_after_absent_to_present_correction(): void
    {
        $session = $this->activeSession();
        [, $a1, $user] = $this->pendingAssignment($session);
        [, $a2] = $this->pendingAssignment($session);
        $service = app(AttendanceService::class);

        $service->markPresent($session, $a1->id, $user);
        $service->markAbsent($session, $a2->id, $user);

        $counts = fn () => [
            'scheduled' => DutyAssignment::where('duty_session_id', $session->id)->count(),
            'present' => DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'present')->count(),
            'absent' => DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'absent')->count(),
            'pending' => DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'pending')->count(),
        ];

        $before = $counts();
        $this->assertSame(2, $before['scheduled']);
        $this->assertSame(1, $before['present']);
        $this->assertSame(1, $before['absent']);

        $service->markPresent($session, $a2->id, $user);

        $after = $counts();
        $this->assertSame($before['scheduled'], $after['scheduled']);
        $this->assertSame($before['present'] + 1, $after['present']);
        $this->assertSame($before['absent'] - 1, $after['absent']);
        $this->assertSame($before['pending'], $after['pending']);
        $this->assertSame($after['scheduled'], $after['present'] + $after['absent'] + $after['pending']);
    }

    public function test_extra_present_stays_separate_after_absent_to_present_correction(): void
    {
        $session = $this->activeSession();
        [, $assignment, $user] = $this->pendingAssignment($session);
        $dept = $assignment->department;
        $service = app(AttendanceService::class);

        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Extra Person']);
        $service->markExtraPresentKnown($session, $extraKg, $dept, $user);

        $service->markAbsent($session, $assignment->id, $user);
        $service->markPresent($session, $assignment->id, $user);

        $this->assertSame(1, ExtraPresent::where('duty_session_id', $session->id)->count());
        $this->assertSame(1, DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'present')->count());
    }

    public function test_repeated_absent_to_present_requests_do_not_duplicate(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $first = $service->markPresent($session, $assignment->id, $user);
        $second = $service->markPresent($session, $assignment->id, $user);

        $this->assertSame('corrected', $first['result']);
        $this->assertSame('already_present', $second['result']);
        $this->assertSame(1, DutyAssignment::where('khidmatguzar_id', $assignment->khidmatguzar_id)->count());
        $this->assertSame(2, AttendanceEvent::where('duty_assignment_id', $assignment->id)->count());
    }

    public function test_present_to_absent_protection_still_holds(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markPresent($session, $assignment->id, $user);
        $blocked = $service->markAbsent($session, $assignment->id, $user);

        $this->assertSame('already_present', $blocked['result']);
        $this->assertSame('present', $assignment->fresh()->current_status);
    }

    public function test_reports_reflect_corrected_present_status_via_current_status(): void
    {
        [$session, $assignment, $user] = $this->pendingAssignment();
        $service = app(AttendanceService::class);

        $service->markAbsent($session, $assignment->id, $user);
        $service->markPresent($session, $assignment->id, $user);

        $report = app(ReportService::class)->sessionReport($session);

        $this->assertSame(1, $report['present']);
        $this->assertSame(0, $report['absent']);
    }

    public function test_eod_review_exposes_all_five_counters(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();

        // 3 present, 84 absent, 0 pending, 1 extra — mirrors the real production state reported.
        $lastAssignment = null;
        for ($i = 0; $i < 3; $i++) {
            [, $a] = $this->pendingAssignment($session);
            app(AttendanceService::class)->markPresent($session, $a->id, $user);
            $lastAssignment = $a;
        }
        for ($i = 0; $i < 84; $i++) {
            [, $a] = $this->pendingAssignment($session);
            app(AttendanceService::class)->markAbsent($session, $a->id, $user);
            $lastAssignment = $a;
        }
        $extraKg = Khidmatguzar::create(['its_id' => (string) random_int(10000000, 99999999), 'full_name' => 'Extra']);
        $result = app(AttendanceService::class)->markExtraPresentKnown($session, $extraKg, $lastAssignment->department, $user);
        $this->assertSame('marked', $result['result']);

        $scheduled = DutyAssignment::where('duty_session_id', $session->id)->count();
        $present = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'present')->count();
        $absent = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'absent')->count();
        $pending = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'pending')->count();
        $extra = ExtraPresent::where('duty_session_id', $session->id)->count();

        $this->assertSame(87, $scheduled);
        $this->assertSame(3, $present);
        $this->assertSame(84, $absent);
        $this->assertSame(0, $pending);
        $this->assertSame(1, $extra);

        $response = $this->actingAs($user)->get(route('attendance.shell.pending', $session));
        $response->assertOk();
        $response->assertSee('87');
        $response->assertSee('84');
        $response->assertSee((string) 3);
    }

    public function test_closed_session_summary_shows_present_plus_absent_equals_scheduled(): void
    {
        $session = $this->activeSession();
        $user = $this->admin();
        [, $a1] = $this->pendingAssignment($session);
        [, $a2] = $this->pendingAssignment($session);
        $service = app(AttendanceService::class);
        $service->markPresent($session, $a1->id, $user);
        $service->markAbsent($session, $a2->id, $user);
        $service->closeSession($session, $user);

        $scheduled = DutyAssignment::where('duty_session_id', $session->id)->count();
        $present = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'present')->count();
        $absent = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'absent')->count();
        $pending = DutyAssignment::where('duty_session_id', $session->id)->where('current_status', 'pending')->count();

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame(0, $pending);
        $this->assertSame($scheduled, $present + $absent);
    }
}
