<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\Miqaat;
use App\Models\User;
use App\Models\Venue;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7 completion pass: Trends, Report Builder, and the Management
 * Summary export (both now backed by ReportService::managementSummary()
 * as a single source of truth shared with the Analytics Summary page).
 * Alert behavior itself is unchanged and already covered by
 * OperationalAlertsTest — these tests only cover the newly added
 * capabilities.
 */
class Phase7CompletionTest extends TestCase
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

    private function assign(DutySession $session, Department $dept, string $its, string $status = 'present', ?int $markedBy = null): DutyAssignment
    {
        $batch = ImportBatch::firstOrCreate(
            ['duty_session_id' => $session->id, 'original_filename' => 'f.csv'],
            ['uploaded_by' => $this->admin()->id, 'file_type' => 'csv', 'status' => 'completed']
        );
        $kg = Khidmatguzar::firstOrCreate(['its_id' => $its], ['full_name' => 'Person '.$its]);

        return DutyAssignment::create([
            'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
            'department_id' => $dept->id, 'source_row_number' => 1, 'assignment_fingerprint' => 'fp-'.uniqid(),
            'venue_name_raw' => $dept->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => $status,
            'attendance_marked_by' => $markedBy,
        ]);
    }

    // --- TRENDS: insufficient-data state -------------------------------------------------

    public function test_trends_show_insufficient_data_state_below_minimum_dates(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '30000001', 'present');

        $response = $this->actingAs($this->admin())->get(route('analytics.trends', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertFalse($response->viewData('attendanceSufficient'), 'One date of data must not be enough to claim a trend.');
        $response->assertSee('Not enough historical data');
    }

    // --- TRENDS: correct daily aggregation (sum of sums, not average of percentages) -------

    public function test_trends_daily_attendance_rate_is_aggregate_not_average_of_percentages(): void
    {
        $dept = $this->department('Department A');
        $dates = [];
        for ($i = 1; $i <= 5; $i++) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        // Day 1: a tiny session, 1/1 present = 100%.
        $s1 = DutySession::create(['name' => 'S1', 'date' => $dates[0], 'status' => 'closed']);
        $this->assign($s1, $dept, '31000001', 'present');

        // Day 2: a much bigger session, 1/9 present = 11.1%.
        $s2 = DutySession::create(['name' => 'S2', 'date' => $dates[1], 'status' => 'closed']);
        $this->assign($s2, $dept, '31000002', 'present');
        for ($i = 3; $i <= 10; $i++) {
            $this->assign($s2, $dept, '31000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'absent');
        }

        // 3 more days with data so the 5-date minimum is met.
        for ($i = 2; $i <= 4; $i++) {
            $s = DutySession::create(['name' => 'Sx'.$i, 'date' => $dates[$i], 'status' => 'closed']);
            $this->assign($s, $dept, '320000'.$i, 'present');
        }

        $response = $this->actingAs($this->admin())->get(route('analytics.trends', [
            'from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('attendanceSufficient'));

        $byDate = collect($response->viewData('attendanceByDate'))->keyBy(fn ($row) => Carbon::parse($row['date'])->toDateString());
        $this->assertSame(100.0, $byDate[$dates[0]]['rate']);
        // Day 2's daily aggregate is 1/9 = 11.1%, NOT an average with anything else on that same day.
        $this->assertEqualsWithDelta(11.1, $byDate[$dates[1]]['rate'], 0.05);
    }

    // --- TRENDS: IST/UTC boundary for planning trend dates ----------------------------------

    public function test_planning_trend_uses_planned_date_correctly_within_ist_range(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $dept = $this->department('Department A');
        $admin = $this->admin();

        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active', 'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id]);
        $plan = EventPlan::create([
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->toDateString(), 'status' => 'finalized',
            'forecast_snapshot' => ['has_history' => false],
            'departments' => [['department_id' => $dept->id, 'name' => 'Department A', 'recommended' => 5, 'planned' => 5]],
            'recommended_total' => 5, 'planned_total' => 5, 'duty_session_id' => $session->id, 'created_by' => $admin->id,
        ]);
        $this->assign($session, $dept, '33000001', 'present');
        $this->assign($session, $dept, '33000002', 'present');

        $response = $this->actingAs($admin)->get(route('analytics.trends', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
        ]));

        $point = collect($response->viewData('planningTrend'))->firstWhere('date', $plan->planned_date->toDateString());
        $this->assertNotNull($point);
        $this->assertSame(5, $point['planned']);
        $this->assertSame(2, $point['actual']);
    }

    // --- REPORT BUILDER: filters, authorization, and export consistency ----------------------

    public function test_report_builder_department_and_status_filters_combine(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $deptA = $this->department('Department A');
        $deptB = $this->department('Department B');
        $this->assign($session, $deptA, '34000001', 'present');
        $this->assign($session, $deptA, '34000002', 'absent');
        $this->assign($session, $deptB, '34000003', 'present');

        $response = $this->actingAs($this->admin())->get(route('reports.builder', [
            'department_id' => $deptA->id, 'status' => 'present',
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('results')->total(), 'Department + status filters must combine (AND), not be applied independently.');
        $this->assertSame(1, $response->viewData('totals')['scheduled']);
    }

    public function test_report_builder_operator_filter_matches_who_marked_attendance(): void
    {
        $admin = $this->admin();
        $otherOperator = User::factory()->create(['role' => 'operator']);
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '35000001', 'present', $admin->id);
        $this->assign($session, $dept, '35000002', 'present', $otherOperator->id);

        $response = $this->actingAs($admin)->get(route('reports.builder', ['operator_id' => $admin->id]));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('results')->total());
    }

    public function test_report_builder_empty_result_state(): void
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder', ['department_id' => 999999]));

        $response->assertOk()->assertSee('No assignments match these filters');
    }

    public function test_report_builder_export_totals_match_preview_totals(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '36000001', 'present');
        $this->assign($session, $dept, '36000002', 'absent');

        $reports = app(ReportService::class);
        $previewTotals = $reports->attendanceDetailTotals(['department_id' => $dept->id]);
        $exportRows = $reports->attendanceDetailQuery(['department_id' => $dept->id])->get();

        $this->assertSame(2, $previewTotals['scheduled']);
        $this->assertSame(2, $exportRows->count(), 'Export must include the full filtered set, not just a page.');
    }

    public function test_report_builder_pdf_and_excel_downloads_succeed(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '37000001', 'present');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.builder.pdf'))->assertOk();
        $this->actingAs($admin)->get(route('reports.builder.excel'))->assertOk();
    }

    /**
     * Superseded by the granular permission system: Report Builder
     * (build_reports) became Admin-only by default — a plain Viewer is
     * rejected here unless explicitly granted. See PermissionOverridesTest.
     */
    public function test_viewer_without_a_grant_cannot_use_report_builder(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get(route('reports.builder'))->assertForbidden();
    }

    // --- MANAGEMENT SUMMARY EXPORT: numbers match, IST generated-at, formats succeed -------

    public function test_management_summary_export_numbers_match_the_summary_page(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '38000001', 'present');
        $this->assign($session, $dept, '38000002', 'absent');

        $reports = app(ReportService::class);
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();
        $summary = $reports->managementSummary($from, $to);

        $this->assertSame(2, $summary['scheduled']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(50.0, $summary['rate']);
        $this->assertInstanceOf(Carbon::class, $summary['generated_at']);
        // generated_at must be IST (UTC+5:30), not the raw UTC "now".
        $this->assertSame(config('app.operational_timezone'), $summary['generated_at']->getTimezone()->getName());
    }

    public function test_management_summary_pdf_and_excel_downloads_succeed(): void
    {
        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active']);
        $dept = $this->department('Department A');
        $this->assign($session, $dept, '39000001', 'present');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.management-summary.pdf'))->assertOk();
        $this->actingAs($admin)->get(route('reports.management-summary.excel'))->assertOk();
    }

    // --- FEEDBACK: forecast/plan/actual reconciliation, multi-department, planned=0 --------

    public function test_management_summary_reflects_multi_department_its_and_planned_zero_correctly(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $deptA = $this->department('Department A');
        $deptWalk = $this->department('Walk-in Dept');
        $admin = $this->admin();

        $session = DutySession::create(['name' => 'S', 'date' => now()->toDateString(), 'status' => 'active', 'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id]);
        EventPlan::create([
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->toDateString(), 'status' => 'finalized',
            'forecast_snapshot' => ['has_history' => false],
            'departments' => [
                ['department_id' => $deptA->id, 'name' => 'Department A', 'recommended' => 5, 'planned' => 5],
                ['department_id' => $deptWalk->id, 'name' => 'Walk-in Dept', 'recommended' => 0, 'planned' => 0],
            ],
            'recommended_total' => 5, 'planned_total' => 5, 'duty_session_id' => $session->id, 'created_by' => $admin->id,
        ]);

        // Same ITS in two departments: must count as 2 actual assignments, never 1.
        $kg = Khidmatguzar::create(['its_id' => '40000001', 'full_name' => 'Dual']);
        $batch = ImportBatch::create(['duty_session_id' => $session->id, 'uploaded_by' => $admin->id, 'original_filename' => 'f.csv', 'file_type' => 'csv', 'status' => 'completed']);
        DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $deptA->id, 'source_row_number' => 1, 'assignment_fingerprint' => 'fp-a', 'venue_name_raw' => $deptA->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => 'present']);
        DutyAssignment::create(['duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id, 'department_id' => $deptWalk->id, 'source_row_number' => 2, 'assignment_fingerprint' => 'fp-b', 'venue_name_raw' => $deptWalk->name, 'full_name_snapshot' => $kg->full_name, 'current_status' => 'present']);

        $summary = app(ReportService::class)->managementSummary(now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame(2, $summary['actual'], 'Actual Assigned must be 2 (one per department), never collapsed to 1 by ITS.');
        $this->assertGreaterThanOrEqual(1, $summary['underplannedDepartments'], 'Walk-in Dept (Planned 0, Actual 1) must remain visible as underplanned.');
    }
}
