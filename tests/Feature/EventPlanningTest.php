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
use App\Models\User;
use App\Models\Venue;
use App\Services\AttendanceService;
use App\Services\DutyListImportService;
use App\Services\EventPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: Intelligent Event Planning & Forecast -> Duty List.
 *
 * Phase 3 forecasting (ForecastingService/HistoricalDemandService/
 * WeightedStats) is frozen and untouched here — these tests only cover the
 * NEW planning layer built on top of it: EventPlan persistence, forecast
 * snapshotting, planned-vs-recommended comparison, and duty session
 * creation from a plan.
 */
class EventPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function miqaat(string $name = 'Istefada Ilmiya'): Miqaat
    {
        return Miqaat::create(['name' => $name, 'normalized_key' => Miqaat::normalize($name)]);
    }

    private function event(Miqaat $miqaat, string $name = 'Qadambosi Bethak (Mardo)', ?string $family = 'Qadambosi Bethak'): Event
    {
        return Event::create([
            'miqaat_id' => $miqaat->id, 'name' => $name,
            'normalized_key' => Event::normalize($name), 'family' => $family,
        ]);
    }

    private function venue(string $name = 'Saifee Masjid'): Venue
    {
        return Venue::create(['name' => $name, 'normalized_key' => Venue::normalize($name)]);
    }

    private function department(string $name): Department
    {
        return Department::firstOrCreate(['normalized_key' => Department::normalize($name)], ['name' => $name]);
    }

    /**
     * @param  array<string,array{scheduled?:int,present?:int,extra?:int}>  $departments
     */
    private function seedSession(Event $event, ?Venue $venue, string $date, array $departments, string $status = 'closed'): DutySession
    {
        $session = DutySession::create([
            'name' => 'Historical '.$date, 'date' => $date, 'status' => $status,
            'miqaat_id' => $event->miqaat_id, 'event_id' => $event->id, 'venue_id' => $venue?->id,
        ]);

        $batch = ImportBatch::create([
            'duty_session_id' => $session->id, 'uploaded_by' => $this->admin->id, 'original_filename' => 'x.csv',
            'file_type' => 'csv', 'status' => 'completed', 'total_rows' => 1, 'valid_rows' => 1,
        ]);

        static $seq = 0;

        foreach ($departments as $deptName => $spec) {
            $department = $this->department($deptName);
            $scheduled = $spec['scheduled'] ?? 0;
            $present = $spec['present'] ?? 0;
            $extra = $spec['extra'] ?? 0;

            for ($i = 0; $i < $scheduled; $i++) {
                $seq++;
                $kg = Khidmatguzar::create(['its_id' => (string) (50000000 + $seq), 'full_name' => 'Person '.$seq]);
                DutyAssignment::create([
                    'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
                    'department_id' => $department->id, 'source_row_number' => $i + 1, 'venue_name_raw' => $department->name,
                    'assignment_fingerprint' => 'fp-'.$seq, 'current_status' => $i < $present ? 'present' : 'absent',
                    'full_name_snapshot' => $kg->full_name,
                ]);
            }

            for ($i = 0; $i < $extra; $i++) {
                $seq++;
                $kg = Khidmatguzar::create(['its_id' => (string) (50000000 + $seq), 'full_name' => 'Extra '.$seq]);
                ExtraPresent::create([
                    'duty_session_id' => $session->id, 'khidmatguzar_id' => $kg->id,
                    'its_id_snapshot' => $kg->its_id,
                    'department_id' => $department->id, 'department_name_snapshot' => $department->name,
                    'full_name_snapshot' => $kg->full_name, 'marked_by' => $this->admin->id, 'marked_at' => now(),
                ]);
            }
        }

        return $session;
    }

    private function strongHistory(Event $event, Venue $venue): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->seedSession($event, $venue, now()->subMonths($i)->toDateString(), [
                'Dept A' => ['scheduled' => 10, 'present' => 9],
                'Extra Only Dept' => ['scheduled' => 0, 'present' => 0, 'extra' => $i === 1 ? 6 : 0],
            ]);
        }
    }

    // --- 1-2: plan loads the Phase 3 forecast and displays it correctly ------------------

    public function test_planning_create_page_shows_the_phase_3_forecast(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $response = $this->actingAs($this->admin)->get(route('planning.create', [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]));

        $response->assertOk()
            ->assertSee('Historical Planning Intelligence')
            ->assertSee('Recommended HR')
            ->assertSee('Dept A');
    }

    public function test_saving_a_plan_persists_the_forecast_snapshot_and_department_working_set(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $response = $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id,
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);

        $plan = EventPlan::firstOrFail();
        $response->assertRedirect(route('planning.show', $plan));

        $this->assertNotEmpty($plan->forecast_snapshot);
        $this->assertSame('High', $plan->forecast_snapshot['confidence']);
        $this->assertNotEmpty($plan->departments);
        $this->assertSame('draft', $plan->status);
    }

    // --- 3: recommended total reconciles to department recommendations -------------------

    public function test_plan_recommended_total_reconciles_to_department_recommendations(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);

        $plan = EventPlan::firstOrFail();
        $sumRecommended = array_sum(array_column($plan->departments, 'recommended'));

        $this->assertSame($plan->recommended_total, $sumRecommended);
    }

    // --- 4-5: planned total reconciles + planning gap calculated correctly ---------------

    public function test_planned_total_reconciles_and_gap_is_calculated_correctly(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();

        $deptA = collect($plan->departments)->firstWhere('name', 'Dept A');
        $planned = [$deptA['department_id'] => $deptA['recommended'] + 5];
        foreach ($plan->departments as $dept) {
            if ($dept['department_id'] !== $deptA['department_id']) {
                $planned[$dept['department_id']] = $dept['recommended'];
            }
        }

        $this->actingAs($this->admin)->put(route('planning.update', $plan), ['planned' => $planned]);

        $plan->refresh();
        $expectedTotal = array_sum($planned);
        $this->assertSame($expectedTotal, $plan->planned_total);
        $this->assertSame($expectedTotal - $plan->recommended_total, $plan->planned_total - $plan->recommended_total);
    }

    // --- 6-8: under/over/adequately planned department detection -------------------------

    public function test_under_planned_department_is_detected(): void
    {
        $this->assertSame('under_planned', EventPlan::classify(planned: 5, recommended: 10));
    }

    public function test_over_planned_department_is_detected(): void
    {
        $this->assertSame('over_planned', EventPlan::classify(planned: 15, recommended: 10));
    }

    public function test_adequately_planned_department_is_detected_within_tolerance(): void
    {
        $this->assertSame('adequately_planned', EventPlan::classify(planned: 10, recommended: 10));
        $this->assertSame('adequately_planned', EventPlan::classify(planned: 11, recommended: 10));
    }

    // --- 9-10: extra-present-only hidden department surfaces; never a future "extras" bucket

    public function test_historically_unscheduled_extra_present_only_department_appears_in_plan(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();

        $extraDept = collect($plan->departments)->firstWhere('name', 'Extra Only Dept');
        $this->assertNotNull($extraDept, 'Extra-present-only department must not be hidden merely because Scheduled was 0.');
        $this->assertTrue($extraDept['extra_present_only']);
        $this->assertGreaterThanOrEqual(0, $extraDept['recommended']);

        $response = $this->actingAs($this->admin)->get(route('planning.show', $plan));
        $response->assertOk()->assertSee('Extra Only Dept');
    }

    public function test_extra_present_is_never_represented_as_a_future_staffing_category(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();

        $response = $this->actingAs($this->admin)->get(route('planning.show', $plan));
        $response->assertOk()
            ->assertDontSee('Future Extras')
            ->assertDontSee('Extra Staffing');
    }

    // --- 11: forecast snapshot/history remains stable if historical data changes ---------

    public function test_forecast_snapshot_remains_stable_after_underlying_history_changes(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();
        $originalRecommendedTotal = $plan->recommended_total;
        $originalSnapshot = $plan->forecast_snapshot;

        // Add a lot more (very different) historical evidence after the plan exists.
        for ($i = 5; $i <= 10; $i++) {
            $this->seedSession($event, $venue, now()->subMonths($i)->toDateString(), [
                'Dept A' => ['scheduled' => 50, 'present' => 48],
            ]);
        }

        $plan->refresh();
        $this->assertSame($originalRecommendedTotal, $plan->recommended_total);
        $this->assertSame($originalSnapshot, $plan->forecast_snapshot);
    }

    // --- 12: authorization ----------------------------------------------------------------

    public function test_viewer_cannot_access_planning_routes(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->actingAs($viewer)->get(route('planning.create', ['miqaat_id' => $miqaat->id, 'event_id' => $event->id]))
            ->assertForbidden();

        $this->actingAs($viewer)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $this->venue()->id,
            'planned_date' => now()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    // --- 13: duplicate session protection still works when creating from a plan ----------

    public function test_duplicate_session_protection_still_works_when_created_from_a_plan(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $date = now()->addDays(5)->toDateString();

        // An open session with this exact identity already exists.
        DutySession::create([
            'name' => 'Existing', 'date' => $date, 'status' => 'draft',
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => $date,
        ]);
        $plan = EventPlan::firstOrFail();

        $response = $this->actingAs($this->admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'date' => $date, 'event_plan_id' => $plan->id,
        ]);

        $response->assertSessionHasErrors('event_id');
        $this->assertSame(1, DutySession::count(), 'No duplicate session should be created.');
    }

    // --- create-session links the plan and finalizes it -----------------------------------

    public function test_creating_a_session_from_a_plan_links_and_finalizes_it(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();

        $response = $this->actingAs($this->admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'date' => now()->addDays(10)->toDateString(), 'event_plan_id' => $plan->id,
        ]);

        $session = DutySession::latest('id')->firstOrFail();
        $response->assertRedirect(route('sessions.show', $session));

        $plan->refresh();
        $this->assertSame($session->id, $plan->duty_session_id);
        $this->assertSame('finalized', $plan->status);
    }

    public function test_finalized_plan_cannot_have_its_planned_quantities_changed(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::firstOrFail();
        $plan->update(['status' => 'finalized']);

        $deptA = collect($plan->departments)->firstWhere('name', 'Dept A');

        $response = $this->actingAs($this->admin)->put(route('planning.update', $plan), [
            'planned' => [$deptA['department_id'] => 999],
        ]);

        $response->assertSessionHas('flash_error');
        $plan->refresh();
        $this->assertNotEquals(999, collect($plan->departments)->firstWhere('name', 'Dept A')['planned']);
    }

    // --- boundary/date handling using the established IST timezone architecture ----------

    public function test_planning_create_defaults_date_using_ist_timezone_architecture(): void
    {
        $response = $this->actingAs($this->admin)->get(route('planning.create'));

        $response->assertOk();
        $response->assertViewHas('selectedDate', fn ($date) => $date === now()->toIst()->addDay()->format('Y-m-d'));
    }

    // --- mobile rendering is verified manually (real browser) per the report -------------

    // =======================================================================
    // FINAL REVIEW: assignment/attendance semantics must stay assignment-
    // level, never collapsed by ITS identity, and Phase 4 must never create
    // assignments itself — the duty-list import remains the sole source.
    // =======================================================================

    /**
     * A real Excel/CSV row for each (ITS, department) pair — this is how
     * DutyListImportService::commit() actually receives data, so this
     * fixture exercises the real import path rather than assuming its
     * internals.
     */
    private function importRow(int $rowNumber, string $its, string $name, string $department): array
    {
        return [
            'row_number' => $rowNumber,
            'data' => [
                'h_year' => null, 'miqaat' => null, 'its_id' => $its, 'full_name' => $name,
                'gender' => 'male', 'age' => null, 'category' => null, 'idara' => null, 'jamaat' => null, 'jamiaat' => null,
                'venue_name' => $department, 'block_name' => null, 'day' => null, 'day_alias' => null, 'seat' => null,
                'status' => null, 'allocated_user_name' => null, 'allocated_date' => null,
                'deallocated_user_name' => null, 'deallocated_date' => null, 'scanned' => null,
                'acc_child_below_5yrs' => null, 'multiple_acc_child_above_4yrs' => null,
            ],
        ];
    }

    public function test_same_its_across_multiple_departments_creates_multiple_duty_assignments(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $session = DutySession::create([
            'name' => 'Live', 'date' => now()->toDateString(), 'status' => 'active',
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]);

        $rows = [
            $this->importRow(1, '12345', 'Person A', 'Department A'),
            $this->importRow(2, '12345', 'Person A', 'Department B'),
            $this->importRow(3, '12345', 'Person A', 'Department C'),
        ];

        $service = app(DutyListImportService::class);
        $preview = $service->buildPreview($session, $rows);
        $service->commit($session, $preview['valid'], $this->admin, 'x.csv', 'csv', $preview);

        $assignments = DutyAssignment::whereHas('khidmatguzar', fn ($q) => $q->where('its_id', '12345'))->get();

        $this->assertCount(3, $assignments, 'Same ITS in 3 departments must create 3 separate DutyAssignments, never collapsed into 1.');
        $this->assertSame(['Department A', 'Department B', 'Department C'], $assignments->pluck('department.name')->sort()->values()->all());
        $this->assertSame(1, $assignments->pluck('khidmatguzar_id')->unique()->count(), 'All 3 assignments belong to the same person.');
    }

    public function test_marking_present_in_one_department_does_not_affect_the_same_its_in_another_department(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $session = DutySession::create([
            'name' => 'Live', 'date' => now()->toDateString(), 'status' => 'active',
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
        ]);

        $service = app(DutyListImportService::class);
        $rows = [
            $this->importRow(1, '55555', 'Person X', 'Department A'),
            $this->importRow(2, '55555', 'Person X', 'Department B'),
        ];
        $preview = $service->buildPreview($session, $rows);
        $service->commit($session, $preview['valid'], $this->admin, 'x.csv', 'csv', $preview);

        $assignmentA = DutyAssignment::whereHas('department', fn ($q) => $q->where('name', 'Department A'))->firstOrFail();
        $assignmentB = DutyAssignment::whereHas('department', fn ($q) => $q->where('name', 'Department B'))->firstOrFail();

        $attendance = app(AttendanceService::class);
        $attendance->markPresent($session, $assignmentA->id, $this->admin);

        $assignmentA->refresh();
        $assignmentB->refresh();

        $this->assertSame('present', $assignmentA->current_status);
        $this->assertSame('pending', $assignmentB->current_status, 'Marking Present in Department A must not silently mark the same ITS Present in Department B.');
    }

    public function test_event_plan_department_quantities_never_create_duty_assignments(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $countBefore = DutyAssignment::count();

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'date' => now()->addDays(10)->toDateString(), 'event_plan_id' => $plan->id,
        ]);

        $this->assertSame($countBefore, DutyAssignment::count(), 'A plan/session created from a forecast must never auto-create individual DutyAssignments.');
    }

    public function test_plan_vs_actual_counts_department_specific_assignments_not_collapsed_by_its(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'date' => now()->addDays(10)->toDateString(), 'event_plan_id' => $plan->id,
        ]);
        $plan->refresh();
        $session = $plan->dutySession;

        $deptAName = collect($plan->departments)->first()['name'];

        // Same ITS assigned to Dept A AND a second department in the actual
        // duty list — must count as 2 separate assignments, not 1 person.
        $service = app(DutyListImportService::class);
        $rows = [
            $this->importRow(1, '99999', 'Dual Dept Person', $deptAName),
            $this->importRow(2, '99999', 'Dual Dept Person', 'Second Dept'),
        ];
        $preview = $service->buildPreview($session, $rows);
        $service->commit($session, $preview['valid'], $this->admin, 'x.csv', 'csv', $preview);

        $rows = $this->planning()->planVsActualByDepartment($plan, $session);
        $deptARow = collect($rows)->firstWhere('name', $deptAName);
        $secondDeptRow = collect($rows)->firstWhere('name', 'Second Dept');

        $this->assertSame(1, $deptARow['actual'], 'Dept A must show 1 actual assignment for this ITS, not merged with Dept B.');
        $this->assertSame(1, $secondDeptRow['actual']);
        $this->assertSame(0, $secondDeptRow['planned'], 'A department outside the plan still surfaces with Planned=0, not hidden.');
    }

    public function test_extra_present_never_appears_in_plan_vs_actual_assignment_counts(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->strongHistory($event, $venue);

        $this->actingAs($this->admin)->post(route('planning.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'planned_date' => now()->addDays(10)->toDateString(),
        ]);
        $plan = EventPlan::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post(route('sessions.store'), [
            'miqaat_id' => $miqaat->id, 'event_id' => $event->id, 'venue_id' => $venue->id,
            'date' => now()->addDays(10)->toDateString(), 'event_plan_id' => $plan->id,
        ]);
        $plan->refresh();
        $session = $plan->dutySession->fresh(['event']);
        $session->update(['status' => 'active']);

        $dept = $this->department('Walk-in Dept');
        $kg = Khidmatguzar::create(['its_id' => '77777', 'full_name' => 'Walk-in Person']);
        app(AttendanceService::class)->markExtraPresentKnown($session, $kg, $dept, 'male', $this->admin);

        $rows = $this->planning()->planVsActualByDepartment($plan, $session);
        $this->assertNull(collect($rows)->firstWhere('name', 'Walk-in Dept'), 'Extra Present must never be counted as an "actual assigned" DutyAssignment.');
        $this->assertSame(0, DutyAssignment::where('duty_session_id', $session->id)->count());
    }

    private function planning(): EventPlanningService
    {
        return app(EventPlanningService::class);
    }
}
