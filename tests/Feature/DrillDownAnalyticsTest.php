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
 * Phase 4 (Drill-Down Analytics): Overview -> Department -> Status ->
 * Member List -> Khidmatguzar -> Assignment -> Attendance Event. These
 * tests focus on the one genuinely new piece of logic — the combined
 * department+status+session filter on the Directory — and the new
 * assignment-level event drill-down page. Everything else reuses
 * already-tested controllers/views.
 */
class DrillDownAnalyticsTest extends TestCase
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

    private function department(?string $name = null): Department
    {
        $name = $name ?? 'DEPT-'.uniqid();

        return Department::create(['name' => $name, 'normalized_key' => Department::normalize($name)]);
    }

    private function assignment(DutySession $session, ImportBatch $batch, Khidmatguzar $kg, Department $dept): DutyAssignment
    {
        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name,
        ]);
    }

    /**
     * The core correctness requirement: a person absent in Department A must
     * NOT appear when drilling into "Department B / Absent", even though
     * they do have an assignment in B (just not an absent one there) and an
     * absent assignment (just not in B). Each filter must apply to the SAME
     * assignment, not be satisfied independently.
     */
    public function test_department_and_status_drilldown_requires_both_on_the_same_assignment(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $deptA = $this->department('DEPT-A');
        $deptB = $this->department('DEPT-B');
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $kg = Khidmatguzar::create(['its_id' => '50000001', 'full_name' => 'Cross Department Person']);
        $assignmentA = $this->assignment($session, $batch, $kg, $deptA); // will be marked absent
        $this->assignment($session, $batch, $kg, $deptB); // stays pending in B

        app(AttendanceService::class)->markAbsent($session, $assignmentA->id, $admin);

        // Genuinely absent in Dept A -> must appear.
        $this->actingAs($admin)
            ->get(route('analytics.profile-search', ['department_id' => $deptA->id, 'status' => 'absent']))
            ->assertSee('Cross Department Person');

        // Has an assignment in Dept B (pending) and is absent somewhere else
        // (Dept A) — but NOT absent in Dept B — must NOT appear here.
        $this->actingAs($admin)
            ->get(route('analytics.profile-search', ['department_id' => $deptB->id, 'status' => 'absent']))
            ->assertDontSee('Cross Department Person');
    }

    public function test_session_scoped_drilldown_narrows_to_that_session_only(): void
    {
        $admin = $this->admin();
        $sessionA = $this->activeSession();
        $sessionB = $this->activeSession();
        $dept = $this->department();
        $batchA = ImportBatch::create(['duty_session_id' => $sessionA->id, 'uploaded_by' => $admin->id, 'original_filename' => 'a.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $batchB = ImportBatch::create(['duty_session_id' => $sessionB->id, 'uploaded_by' => $admin->id, 'original_filename' => 'b.csv', 'file_type' => 'csv', 'status' => 'completed']);

        $kgInA = Khidmatguzar::create(['its_id' => '50000002', 'full_name' => 'Session A Person']);
        $kgInB = Khidmatguzar::create(['its_id' => '50000003', 'full_name' => 'Session B Person']);
        $this->assignment($sessionA, $batchA, $kgInA, $dept);
        $this->assignment($sessionB, $batchB, $kgInB, $dept);

        $response = $this->actingAs($admin)->get(route('analytics.profile-search', ['session_id' => $sessionA->id]));

        $response->assertSee('Session A Person')->assertDontSee('Session B Person');
    }

    public function test_assignment_detail_shows_event_trail_in_order(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '50000004', 'full_name' => 'Event Trail Person']);
        $assignment = $this->assignment($session, $batch, $kg, $dept);

        $service = app(AttendanceService::class);
        $service->markAbsent($session, $assignment->id, $admin);
        $service->markPresent($session, $assignment->id, $admin); // correction

        $response = $this->actingAs($admin)->get(route('analytics.assignment', $assignment));

        $response->assertOk();
        $response->assertSeeInOrder(['Absent', 'Present']); // chronological, correction visible after the original
    }

    public function test_assignment_detail_with_no_events_shows_pending_state(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '50000005', 'full_name' => 'Untouched Person']);
        $assignment = $this->assignment($session, $batch, $kg, $dept);

        $response = $this->actingAs($admin)->get(route('analytics.assignment', $assignment));

        $response->assertOk()->assertSee('still Pending', false);
    }

    public function test_viewer_can_view_assignment_detail(): void
    {
        $admin = $this->admin();
        $viewer = User::factory()->viewer()->create();
        $session = $this->activeSession();
        $dept = $this->department();
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '50000006', 'full_name' => 'Person']);
        $assignment = $this->assignment($session, $batch, $kg, $dept);

        $this->actingAs($viewer)->get(route('analytics.assignment', $assignment))->assertOk();
    }

    /**
     * Regression: departmentBreakdown()'s date-range filter used
     * whereBetween('duty_sessions.date', [$from, $to]) with 'Y-m-d' string
     * bounds. Under sqlite (this test suite's driver) the date-cast column
     * is stored with a time component ("2026-09-11 00:00:00"), which sorts
     * lexicographically AFTER the bare 'Y-m-d' upper bound — silently
     * excluding "today" from every default-range analytics view. Fixed via
     * whereDate() (portable across drivers). This proves the exact boundary
     * that broke: a session dated exactly "today", queried with the
     * default (today-inclusive) range.
     */
    public function test_departments_page_includes_a_session_dated_today(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession(); // date = today
        $dept = $this->department('BOUNDARY-DEPT');
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '50000008', 'full_name' => 'Person']);
        $this->assignment($session, $batch, $kg, $dept);

        $response = $this->actingAs($admin)->get(route('analytics.departments'));

        $response->assertOk()->assertSee('BOUNDARY-DEPT')->assertDontSee('No Department data available');
    }

    public function test_department_drilldown_links_present_on_departments_page(): void
    {
        $admin = $this->admin();
        $session = $this->activeSession();
        $dept = $this->department('LINKED-DEPT');
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        $kg = Khidmatguzar::create(['its_id' => '50000007', 'full_name' => 'Person']);
        $this->assignment($session, $batch, $kg, $dept);

        $response = $this->actingAs($admin)->get(route('analytics.departments'));

        $response->assertOk();
        $response->assertSee('status=present', false);
        $response->assertSee('status=absent', false);
        $response->assertSee('status=pending', false);
    }
}
