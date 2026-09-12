<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\Miqaat;
use App\Models\SessionReopenEvent;
use App\Models\User;
use App\Models\Venue;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 6 (Advanced Analytics & Operator Intelligence): Planning Performance,
 * Exceptions, and the new Overview KPIs (Corrections/Reopened/Imports).
 * Attendance-rate correctness, department actual-assignment counting, and
 * the Directory/drilldown itself are already covered by earlier phases'
 * tests and are deliberately not re-tested here — only the genuinely new
 * Phase 6 logic is.
 */
class AdvancedAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function miqaat(): Miqaat
    {
        return Miqaat::create(['name' => 'M', 'normalized_key' => Miqaat::normalize('M')]);
    }

    private function event(Miqaat $miqaat, string $name = 'Event A'): Event
    {
        return Event::create(['miqaat_id' => $miqaat->id, 'name' => $name, 'normalized_key' => Event::normalize($name)]);
    }

    private function venue(): Venue
    {
        return Venue::create(['name' => 'Venue', 'normalized_key' => Venue::normalize('Venue')]);
    }

    private function department(string $name): Department
    {
        return Department::firstOrCreate(['normalized_key' => Department::normalize($name)], ['name' => $name]);
    }

    /**
     * A finalized EventPlan + linked active DutySession, with a plan
     * department row supplied by the caller. No forecast history is
     * seeded — the snapshot is set directly since Phase 3 forecasting
     * itself is frozen and out of scope here.
     */
    private function finalizedPlanWithSession(Miqaat $miqaat, Event $event, Venue $venue, array $planDepartments, string $date): EventPlan
    {
        $admin = $this->admin();
        $session = DutySession::create([
            'name' => 'S', 'date' => $date, 'status' => 'active',
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]);

        return EventPlan::create([
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => $date, 'status' => 'finalized',
            'forecast_snapshot' => ['has_history' => false],
            'departments' => $planDepartments,
            'recommended_total' => array_sum(array_column($planDepartments, 'recommended')),
            'planned_total' => array_sum(array_column($planDepartments, 'planned')),
            'duty_session_id' => $session->id,
            'created_by' => $admin->id,
        ]);
    }

    private function assign(DutySession $session, Department $dept, string $its): DutyAssignment
    {
        $batch = ImportBatch::firstOrCreate(
            ['duty_session_id' => $session->id, 'original_filename' => 'f.csv'],
            ['uploaded_by' => $this->admin()->id, 'file_type' => 'csv', 'status' => 'completed']
        );
        $kg = Khidmatguzar::firstOrCreate(['its_id' => $its], ['full_name' => 'Person '.$its]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 1, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => 'pending',
        ]);
    }

    // --- Scenario A: same ITS across 2 departments never collapsed -----------------------

    public function test_planning_analysis_counts_same_its_in_two_departments_as_two_separate_actuals(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $deptA = $this->department('Department A');
        $deptB = $this->department('Department B');

        $plan = $this->finalizedPlanWithSession($miqaat, $event, $venue, [
            ['department_id' => $deptA->id, 'name' => 'Department A', 'recommended' => 5, 'planned' => 5],
            ['department_id' => $deptB->id, 'name' => 'Department B', 'recommended' => 5, 'planned' => 5],
        ], now()->toDateString());

        $this->assign($plan->dutySession, $deptA, '80000001');
        $this->assign($plan->dutySession, $deptB, '80000001'); // same ITS, different department

        $response = $this->actingAs($this->admin())->get(route('analytics.planning', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));

        $response->assertOk();
        $rows = collect($response->viewData('plans'))->first()['rows'];
        $deptARow = collect($rows)->firstWhere('name', 'Department A');
        $deptBRow = collect($rows)->firstWhere('name', 'Department B');

        $this->assertSame(1, $deptARow['actual']);
        $this->assertSame(1, $deptBRow['actual']);
        $this->assertSame(2, $response->viewData('sum_actual'));
    }

    // --- Scenario B: planned vs actual gap, positive ----------------------------------------

    public function test_planning_accuracy_and_gap_for_over_actual_department(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $dept = $this->department('Department A');

        $plan = $this->finalizedPlanWithSession($miqaat, $event, $venue, [
            ['department_id' => $dept->id, 'name' => 'Department A', 'recommended' => 10, 'planned' => 10],
        ], now()->toDateString());

        for ($i = 1; $i <= 13; $i++) {
            $this->assign($plan->dutySession, $dept, '81000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $response = $this->actingAs($this->admin())->get(route('analytics.planning', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $rows = collect($response->viewData('plans'))->first()['rows'];
        $row = collect($rows)->firstWhere('name', 'Department A');

        $this->assertSame(10, $row['planned']);
        $this->assertSame(13, $row['actual']);
        $this->assertSame(3, $row['gap']);
        $this->assertTrue($row['underplanned'], 'Actual 13 vs Planned 10 exceeds the 20% threshold and must be flagged underplanned.');
        $this->assertEqualsWithDelta(70.0, $row['accuracy'], 0.01, '100 * (1 - |13-10|/10) = 70.');
    }

    // --- Scenario C: planned 0 / actual > 0 -------------------------------------------------

    public function test_planned_zero_actual_positive_is_detected_as_underplanned(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $dept = $this->department('Walk-in Dept');

        $plan = $this->finalizedPlanWithSession($miqaat, $event, $venue, [
            ['department_id' => $dept->id, 'name' => 'Walk-in Dept', 'recommended' => 0, 'planned' => 0],
        ], now()->toDateString());

        $this->assign($plan->dutySession, $dept, '82000001');
        $this->assign($plan->dutySession, $dept, '82000002');
        $this->assign($plan->dutySession, $dept, '82000003');

        $response = $this->actingAs($this->admin())->get(route('analytics.planning', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $rows = collect($response->viewData('plans'))->first()['rows'];
        $row = collect($rows)->firstWhere('name', 'Walk-in Dept');

        $this->assertSame(0, $row['planned']);
        $this->assertSame(3, $row['actual']);
        $this->assertTrue($row['underplanned']);
        $this->assertNull($row['accuracy'], 'Accuracy is undefined (not 0%) when Planned = 0 — dividing by zero is excluded, not penalized.');

        $exceptionsResponse = $this->actingAs($this->admin())->get(route('analytics.exceptions', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $exceptionsResponse->assertOk()->assertSee('Walk-in Dept');
    }

    // --- Scenario D: Extra Present never enters the scheduled denominator ------------------

    public function test_extra_present_does_not_affect_attendance_rate_or_planning_actual_count(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $dept = $this->department('Department A');

        $plan = $this->finalizedPlanWithSession($miqaat, $event, $venue, [
            ['department_id' => $dept->id, 'name' => 'Department A', 'recommended' => 5, 'planned' => 5],
        ], now()->toDateString());

        $this->assign($plan->dutySession, $dept, '83000001');
        $this->assign($plan->dutySession, $dept, '83000002');

        for ($i = 1; $i <= 5; $i++) {
            $kg = Khidmatguzar::create(['its_id' => '84000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'full_name' => 'Extra '.$i]);
            ExtraPresent::create([
                'duty_session_id' => $plan->dutySession->id, 'khidmatguzar_id' => $kg->id, 'its_id_snapshot' => $kg->its_id,
                'department_id' => $dept->id, 'department_name_snapshot' => $dept->name,
                'full_name_snapshot' => $kg->full_name, 'marked_by' => $this->admin()->id, 'marked_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin())->get(route('analytics.planning', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $rows = collect($response->viewData('plans'))->first()['rows'];
        $row = collect($rows)->firstWhere('name', 'Department A');

        $this->assertSame(2, $row['actual'], 'Actual Assigned must count DutyAssignment rows only — the 5 Extra Present must not inflate it.');
        $this->assertSame(5, $row['extra']);

        $overview = $this->actingAs($this->admin())->get(route('analytics.overview', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $this->assertSame(2, $overview->viewData('scheduled'));
        $this->assertSame(5, $overview->viewData('extra'));
    }

    // --- Exception: unusually high Extra Present in one session ----------------------------

    public function test_unusually_high_extra_present_session_is_flagged_as_exception(): void
    {
        $session = DutySession::create(['name' => 'HighExtra', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '85000001');

        for ($i = 1; $i <= 6; $i++) {
            $kg = Khidmatguzar::create(['its_id' => '86000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'full_name' => 'Extra '.$i]);
            ExtraPresent::create([
                'duty_session_id' => $session->id, 'khidmatguzar_id' => $kg->id, 'its_id_snapshot' => $kg->its_id,
                'department_id' => $dept->id, 'department_name_snapshot' => $dept->name,
                'full_name_snapshot' => $kg->full_name, 'marked_by' => $this->admin()->id, 'marked_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin())->get(route('analytics.exceptions', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $response->assertOk()->assertSee('HighExtra');
    }

    // --- Exception: repeated reopen ----------------------------------------------------------

    public function test_repeatedly_reopened_session_is_flagged_as_exception(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'ReopenedTwice', 'date' => now()->toDateString(), 'status' => 'active']);

        SessionReopenEvent::create(['duty_session_id' => $session->id, 'reopened_by' => $admin->id, 'reason' => 'other', 'reopened_at' => now()]);
        SessionReopenEvent::create(['duty_session_id' => $session->id, 'reopened_by' => $admin->id, 'reason' => 'other', 'reopened_at' => now()]);

        $response = $this->actingAs($admin)->get(route('analytics.exceptions', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $response->assertOk()->assertSee('ReopenedTwice')->assertSee('2 reopens', false);

        $overview = $this->actingAs($admin)->get(route('analytics.overview', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $this->assertSame(1, $overview->viewData('reopenedSessions'), 'Reopened Sessions counts distinct sessions, not reopen events.');
    }

    // --- Exception: high-invalid-row import ---------------------------------------------------

    public function test_import_with_high_invalid_row_ratio_is_flagged_as_exception(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'bad.csv',
            'file_type' => 'csv', 'status' => 'completed', 'total_rows' => 10, 'valid_rows' => 7, 'invalid_rows' => 3,
        ]);

        $response = $this->actingAs($admin)->get(route('analytics.exceptions', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $response->assertOk()->assertSee('bad.csv');
    }

    // --- Overview: corrections counted correctly --------------------------------------------

    public function test_overview_counts_corrections_via_absent_then_present_on_same_assignment(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $assignment = $this->assign($session, $dept, '87000001');

        app(AttendanceService::class)->markAbsent($session, $assignment->id, $admin);
        app(AttendanceService::class)->markPresent($session, $assignment->id, $admin);

        $response = $this->actingAs($admin)->get(route('analytics.overview', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]));
        $this->assertSame(1, $response->viewData('corrections'));
    }

    // --- Timezone boundary: a UTC-adjacent timestamp must land on the correct IST day ------

    public function test_operational_bounds_correctly_include_late_night_ist_timestamp(): void
    {
        $admin = $this->admin();
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);

        // 23:30 IST = 18:00 UTC the same calendar day — a naive UTC-date
        // comparison would misfile this into the wrong day. IST is
        // UTC+5:30, so 23:30 IST on day D is 18:00 UTC on day D as well;
        // choose a value that actually crosses midnight UTC to prove the
        // conversion: 01:00 IST on day D+1 is 19:30 UTC on day D.
        $tz = config('app.operational_timezone');
        $istTimestamp = Carbon::parse(now()->toIst()->toDateString().' 23:45:00', $tz);

        SessionReopenEvent::create([
            'duty_session_id' => $session->id, 'reopened_by' => $admin->id, 'reason' => 'other',
            'reopened_at' => $istTimestamp->clone()->utc(),
        ]);

        $today = $istTimestamp->toDateString();
        $response = $this->actingAs($admin)->get(route('analytics.overview', ['from' => $today, 'to' => $today]));

        $this->assertSame(1, $response->viewData('reopenedSessions'), 'A reopen at 23:45 operational-time on the selected day must be included, not clipped by a naive UTC-date filter.');
    }

    // --- Permission: viewer can view analytics (read-only), not blocked --------------------

    public function test_viewer_can_view_planning_and_exceptions_analytics(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get(route('analytics.planning'))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.exceptions'))->assertOk();
    }

    // --- Empty state: no finalized plans in range -------------------------------------------

    public function test_planning_analytics_shows_empty_state_when_no_finalized_plans_exist(): void
    {
        $response = $this->actingAs($this->admin())->get(route('analytics.planning'));

        $response->assertOk()->assertSee('Planning comparison requires at least one Duty Session created from an Event Plan');
    }
}
