<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\Miqaat;
use App\Models\User;
use App\Models\Venue;
use App\Services\ForecastingService;
use App\Support\WeightedStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3: Forecasting + Department Intelligence.
 *
 * Canonical formula under test throughout:
 *   Operational Demand = Present + Extra Present   (never Scheduled + Extra)
 * Recommended Scheduled HR is derived FROM the department table, never
 * computed independently of it — SUM(department recommended) === headline
 * is asserted explicitly, not assumed.
 */
class ForecastingServiceTest extends TestCase
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
     * Seeds one historical DutySession with per-department Scheduled/
     * Present/Extra counts.
     *
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
                $kg = Khidmatguzar::create(['its_id' => (string) (40000000 + $seq), 'full_name' => 'Person '.$seq]);
                DutyAssignment::create([
                    'duty_session_id' => $session->id, 'import_batch_id' => $batch->id, 'khidmatguzar_id' => $kg->id,
                    'department_id' => $department->id, 'source_row_number' => $i + 1, 'venue_name_raw' => $department->name,
                    'assignment_fingerprint' => 'fp-'.$seq, 'current_status' => $i < $present ? 'present' : 'absent',
                    'full_name_snapshot' => $kg->full_name,
                ]);
            }

            for ($i = 0; $i < $extra; $i++) {
                $seq++;
                $kg = Khidmatguzar::create(['its_id' => (string) (40000000 + $seq), 'full_name' => 'Extra '.$seq]);
                ExtraPresent::create([
                    'duty_session_id' => $session->id, 'khidmatguzar_id' => $kg->id, 'its_id_snapshot' => $kg->its_id,
                    'full_name_snapshot' => $kg->full_name, 'department_id' => $department->id,
                    'department_name_snapshot' => $department->name, 'marked_by' => $this->admin->id, 'marked_at' => now(),
                ]);
            }
        }

        return $session;
    }

    // --- 1-3: canonical formula ------------------------------------------------

    public function test_operational_demand_is_present_plus_extra_not_scheduled_plus_extra(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();

        // Scheduled 10, Present 8, Extra 3 -> Demand must be 11 (8+3), not 13 (10+3).
        $this->seedSession($event, $venue, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 8, 'extra' => 3],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, $venue);
        $evidence = $forecast['evidence_sessions'][0];

        $this->assertSame(8, $evidence['present']);
        $this->assertSame(3, $evidence['extra']);
        $this->assertSame(11, $evidence['demand']); // present + extra
        $this->assertNotEquals(13, $evidence['demand']); // NOT scheduled + extra
    }

    public function test_planning_gap_equals_demand_minus_scheduled(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 9, 'extra' => 3], // demand 12, gap +2
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $this->assertSame(2, $forecast['evidence_sessions'][0]['planning_gap']);
    }

    // --- 4-7: similarity tiers --------------------------------------------------

    public function test_same_miqaat_and_event_history_is_selected(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(2)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertTrue($forecast['has_history']);
        $this->assertSame('same_event', $forecast['tier']);
    }

    public function test_same_event_at_a_different_venue_still_counts_as_comparable(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venueA = $this->venue('Venue A');
        $venueB = $this->venue('Venue B');

        $this->seedSession($event, $venueA, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        $this->seedSession($event, $venueB, now()->subMonths(2)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 4]]);

        // Forecasting for venue A: both sessions are still "same_event" comparable evidence.
        $forecast = app(ForecastingService::class)->forecast($event, $venueA);

        $this->assertSame(2, $forecast['comparable_count']);
        $this->assertSame(2, $forecast['same_event_count']);
    }

    public function test_venue_match_increases_weight_but_does_not_exclude_other_venues(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venueA = $this->venue('Venue A');
        $venueB = $this->venue('Venue B');

        $this->seedSession($event, $venueA, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        $this->seedSession($event, $venueB, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);

        $forecast = app(ForecastingService::class)->forecast($event, $venueA);
        $weights = collect($forecast['evidence_sessions'])->pluck('weight', 'venue_match');

        // Same recency, so the only difference is the venue-match multiplier.
        $this->assertGreaterThan($weights[false], $weights[true]);
    }

    public function test_event_family_fallback_is_used_when_same_event_history_is_thin(): void
    {
        $miqaat = $this->miqaat();
        $eventA = $this->event($miqaat, 'Qadambosi Bethak (Mardo)', 'Qadambosi Bethak');
        $eventB = $this->event($miqaat, 'Qadambosi Bethak (Bairao)', 'Qadambosi Bethak');

        // Only ONE session for the target event itself (thin) -> family fallback widens the pool.
        $this->seedSession($eventA, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        $this->seedSession($eventB, null, now()->subMonths(2)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);

        $forecast = app(ForecastingService::class)->forecast($eventA, null);

        $this->assertSame(2, $forecast['comparable_count']);
        $this->assertSame(1, $forecast['same_event_count']);
        $this->assertNotSame('same_event', $forecast['tier']);
    }

    // --- 8-9: recency ------------------------------------------------------------

    public function test_recency_weight_formula(): void
    {
        $this->assertEqualsWithDelta(1.0, WeightedStats::recencyWeight(0), 0.0001);
        $this->assertEqualsWithDelta(0.5, WeightedStats::recencyWeight(24), 0.0001);
        $this->assertEqualsWithDelta(0.25, WeightedStats::recencyWeight(48), 0.0001);
    }

    public function test_older_history_receives_lower_weight_than_recent_history(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        $this->seedSession($event, null, now()->subMonths(30)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $byRecency = collect($forecast['evidence_sessions'])->sortByDesc('date')->values();

        $this->assertGreaterThan($byRecency[1]['weight'], $byRecency[0]['weight']);
    }

    // --- 10-12: department-level demand, including Scheduled=0/Extra>0 ----------

    public function test_department_demand_is_present_plus_extra(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 9, 'extra' => 2],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptA = collect($forecast['departments'])->firstWhere('name', 'Dept A');

        $this->assertNotNull($deptA);
        $this->assertGreaterThan(0, $deptA['recommended']);
    }

    public function test_scheduled_zero_extra_present_department_is_detected_and_recommended(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        // Department B: never scheduled, but Extra Present demonstrates real demand.
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 9],
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 8],
        ]);
        $this->seedSession($event, null, now()->subMonths(2)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 9],
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 6],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptB = collect($forecast['departments'])->firstWhere('name', 'Dept B');

        $this->assertNotNull($deptB, 'Extra-present-only department must not disappear from the intelligence.');
        $this->assertTrue($deptB['extra_present_only']);
        $this->assertContains('extra_present_only', $deptB['tags']);
        $this->assertGreaterThan(0, $deptB['recommended'], 'Extra-present-only department must receive a real recommended allocation, not zero.');
    }

    public function test_extra_present_only_department_is_never_labeled_as_a_future_extras_bucket(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 5],
        ]);
        $this->seedSession($event, null, now()->subMonths(2)->toDateString(), [
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 5],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptB = collect($forecast['departments'])->firstWhere('name', 'Dept B');

        // The department itself carries a "recommended" figure like any other — there
        // is no separate "recommended extras" field anywhere in the output shape.
        $this->assertArrayHasKey('recommended', $deptB);
        $this->assertArrayNotHasKey('recommended_extra', $deptB);
        $this->assertArrayNotHasKey('future_extras', $deptB);
    }

    // --- 13-14: no history / sparse department -----------------------------------

    public function test_no_history_produces_no_numeric_recommendation(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertFalse($forecast['has_history']);
        $this->assertArrayNotHasKey('recommended_scheduled_hr', $forecast);
        $this->assertSame('No historical data available for a reliable recommendation.', $forecast['message']);
    }

    public function test_sparse_department_history_is_flagged_honestly(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        // Event has good overall history (4 sessions), but Dept X appears in only 1.
        for ($i = 1; $i <= 4; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), array_filter([
                'Dept A' => ['scheduled' => 10, 'present' => 9],
                'Dept X' => $i === 1 ? ['scheduled' => 2, 'present' => 2] : null,
            ]));
        }

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptX = collect($forecast['departments'])->firstWhere('name', 'Dept X');
        $deptA = collect($forecast['departments'])->firstWhere('name', 'Dept A');

        $this->assertSame('Sparse', $deptX['evidence']);
        $this->assertSame('Strong', $deptA['evidence']);
    }

    // --- 15: outlier dampening ----------------------------------------------------

    public function test_outlier_dampening_caps_extreme_values_in_calculations(): void
    {
        $values = [10.0, 11.0, 9.0, 100.0]; // median ~10.5, 100 is a wild outlier
        $weights = [1.0, 1.0, 1.0, 1.0];

        $damped = WeightedStats::dampOutliers($values, $weights);

        $this->assertLessThan(100.0, max($damped));
        $this->assertSame(10.0, $damped[0]); // non-outlier values untouched
    }

    public function test_outlier_session_does_not_dominate_the_forecast(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 10]]);
        $this->seedSession($event, null, now()->subMonths(2)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 10]]);
        // One wildly abnormal session.
        $this->seedSession($event, null, now()->subMonths(3)->toDateString(), ['Dept A' => ['scheduled' => 200, 'present' => 200]]);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        // Undamped mean of (10,10,200)/3 ≈ 73 — the recommendation must be far below that.
        $this->assertLessThan(50, $forecast['recommended_scheduled_hr']);
    }

    // --- 16: expected attendance is separate from demand ---------------------------

    public function test_expected_attendance_is_not_the_same_as_operational_demand(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 8, 'extra' => 4], // demand 12, present 8
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertSame(8, $forecast['expected_attendance']);
        $this->assertSame(12, $forecast['operational_demand']);
        $this->assertNotSame($forecast['expected_attendance'], $forecast['operational_demand']);
    }

    // --- 17-18: reconciliation + rounding -------------------------------------------

    public function test_department_recommendations_reconcile_exactly_to_the_headline_total(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        for ($i = 1; $i <= 3; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), [
                'Dept A' => ['scheduled' => 17, 'present' => 15, 'extra' => 3],
                'Dept B' => ['scheduled' => 8, 'present' => 7, 'extra' => 1],
                'Dept C' => ['scheduled' => 0, 'present' => 0, 'extra' => 5],
            ]);
        }

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $sumOfDepartments = array_sum(array_column($forecast['departments'], 'recommended'));

        $this->assertSame($forecast['recommended_scheduled_hr'], $sumOfDepartments, 'SUM(department recommended) must equal the headline exactly.');
    }

    public function test_apportionment_is_deterministic_whole_numbers(): void
    {
        $shares = ['a' => 10.4, 'b' => 10.3, 'c' => 10.3]; // sums to 31.0, target 35 (rounded up to nearest 5)
        $result = WeightedStats::apportion($shares, 35);

        $this->assertSame(35, array_sum($result));
        foreach ($result as $v) {
            $this->assertIsInt($v);
        }
    }

    // --- 19: range -------------------------------------------------------------------

    public function test_range_is_wider_for_a_single_comparable_session(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 20, 'present' => 20]]);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertLessThan($forecast['recommended_scheduled_hr'], $forecast['range']['low']);
        $this->assertGreaterThan($forecast['recommended_scheduled_hr'], $forecast['range']['high']);
    }

    // --- 20: confidence ----------------------------------------------------------------

    public function test_confidence_reflects_number_of_same_event_sessions(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        $lowForecast = app(ForecastingService::class)->forecast($event, null);
        $this->assertSame('Low', $lowForecast['confidence']);

        for ($i = 2; $i <= 5; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]]);
        }
        $highForecast = app(ForecastingService::class)->forecast($event, null);
        $this->assertSame('High', $highForecast['confidence']);
    }

    /** Scenario A: 0 same-event history + meaningful same-Miqaat family history -> recommendation exists, reduced confidence. */
    public function test_zero_same_event_but_strong_same_miqaat_family_history_still_produces_a_recommendation(): void
    {
        $miqaat = $this->miqaat();
        $eventA = $this->event($miqaat, 'Qadambosi Bethak (Mardo)', 'Qadambosi Bethak');
        $eventB = $this->event($miqaat, 'Qadambosi Bethak (Bairao)', 'Qadambosi Bethak');

        // 0 sessions for eventA itself; 5 sessions for a sibling event in the same family/Miqaat.
        for ($i = 1; $i <= 5; $i++) {
            $this->seedSession($eventB, null, now()->subMonths($i)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 9]]);
        }

        $forecast = app(ForecastingService::class)->forecast($eventA, null);

        $this->assertTrue($forecast['has_history'], 'NO exact-event evidence must not mean NO recommendation when other meaningful evidence exists.');
        $this->assertSame(0, $forecast['same_event_count']);
        $this->assertGreaterThan(0, $forecast['recommended_scheduled_hr']);
        $this->assertContains($forecast['confidence'], ['Medium', 'Low'], 'Confidence must be reduced, not absent.');
        $this->assertNotSame('Insufficient Data', $forecast['confidence']);
    }

    /** Scenario B: 0 same-event + only cross-Miqaat family history -> recommendation may exist, low confidence. */
    public function test_zero_same_event_and_only_cross_miqaat_family_history_produces_low_confidence_recommendation(): void
    {
        $miqaatA = $this->miqaat('Miqaat A');
        $miqaatB = $this->miqaat('Miqaat B');
        $eventA = $this->event($miqaatA, 'Qadambosi Bethak (Mardo)', 'Qadambosi Bethak');
        $eventCrossMiqaat = $this->event($miqaatB, 'Qadambosi Bethak (Somewhere)', 'Qadambosi Bethak');

        $this->seedSession($eventCrossMiqaat, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 9]]);

        $forecast = app(ForecastingService::class)->forecast($eventA, null);

        $this->assertTrue($forecast['has_history']);
        $this->assertSame(0, $forecast['same_event_count']);
        $this->assertSame('same_family_cross_miqaat', $forecast['tier']);
        $this->assertSame('Low', $forecast['confidence']);
        $this->assertGreaterThan(0, $forecast['recommended_scheduled_hr']);
    }

    /** Scenario C: no meaningful comparable history at all -> no numeric recommendation. */
    public function test_no_meaningful_comparable_history_produces_no_numeric_recommendation(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat, 'Brand New Event', null); // no family either — nothing to fall back to

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertFalse($forecast['has_history']);
        $this->assertArrayNotHasKey('recommended_scheduled_hr', $forecast);
    }

    /** Scenario D: strong exact-event history -> confidence is High, not merely "some recommendation". */
    public function test_strong_exact_event_history_yields_high_confidence(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        for ($i = 1; $i <= 4; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 9]]);
        }

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertSame(4, $forecast['same_event_count']);
        $this->assertSame('High', $forecast['confidence']);
    }

    public function test_explanation_states_which_evidence_tier_actually_drove_the_recommendation(): void
    {
        $miqaat = $this->miqaat();
        $eventA = $this->event($miqaat, 'Qadambosi Bethak (Mardo)', 'Qadambosi Bethak');
        $eventB = $this->event($miqaat, 'Qadambosi Bethak (Bairao)', 'Qadambosi Bethak');

        for ($i = 1; $i <= 3; $i++) {
            $this->seedSession($eventB, null, now()->subMonths($i)->toDateString(), ['Dept A' => ['scheduled' => 10, 'present' => 9]]);
        }

        $forecast = app(ForecastingService::class)->forecast($eventA, null);
        $text = implode(' ', $forecast['explanation']);

        $this->assertStringContainsString('no prior recorded session', $text);
        $this->assertStringContainsString('same Event Family within the same Miqaat', $text);
    }

    // --- 21: zero denominators are safe -------------------------------------------------

    public function test_zero_scheduled_zero_present_zero_extra_does_not_crash(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), []);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertTrue($forecast['has_history']);
        $this->assertSame(0, $forecast['evidence_sessions'][0]['scheduled']);
        $this->assertNull($forecast['planning_gap']['under_planning_pct']); // no scheduled>0 session to compute a ratio from
    }

    // --- 22-26: department/planning-gap classifications ---------------------------------

    public function test_consistently_under_planned_department_is_classified(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        for ($i = 1; $i <= 3; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), [
                'Dept A' => ['scheduled' => 10, 'present' => 10, 'extra' => 3], // demand 13 > scheduled 10, every time
            ]);
        }

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptA = collect($forecast['departments'])->firstWhere('name', 'Dept A');

        $this->assertContains('consistently_under_planned', $deptA['tags']);
    }

    public function test_over_planned_department_is_classified(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        for ($i = 1; $i <= 3; $i++) {
            $this->seedSession($event, null, now()->subMonths($i)->toDateString(), [
                'Dept A' => ['scheduled' => 20, 'present' => 8], // demand 8 < scheduled 20, every time
            ]);
        }

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptA = collect($forecast['departments'])->firstWhere('name', 'Dept A');

        $this->assertContains('over_planned', $deptA['tags']);
    }

    public function test_high_operational_demand_department_shows_a_larger_recommendation(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 50, 'present' => 48],
            'Dept B' => ['scheduled' => 3, 'present' => 3],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptA = collect($forecast['departments'])->firstWhere('name', 'Dept A');
        $deptB = collect($forecast['departments'])->firstWhere('name', 'Dept B');

        $this->assertGreaterThan($deptB['recommended'], $deptA['recommended']);
    }

    public function test_extra_present_only_classification_across_multiple_sessions(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept B' => ['extra' => 4]]);
        $this->seedSession($event, null, now()->subMonths(2)->toDateString(), ['Dept B' => ['extra' => 6]]);

        $forecast = app(ForecastingService::class)->forecast($event, null);
        $deptB = collect($forecast['departments'])->firstWhere('name', 'Dept B');

        $this->assertTrue($deptB['extra_present_only']);
    }

    // --- 27-29: data integrity, closed sessions, legacy sessions -----------------------

    public function test_forecasting_never_mutates_historical_records(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $session = $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 4, 'extra' => 1]]);

        $beforeAssignments = DutyAssignment::where('duty_session_id', $session->id)->get()->toArray();
        $beforeExtra = ExtraPresent::where('duty_session_id', $session->id)->get()->toArray();

        app(ForecastingService::class)->forecast($event, null);
        app(ForecastingService::class)->forecast($event, null); // run twice for good measure

        $this->assertEquals($beforeAssignments, DutyAssignment::where('duty_session_id', $session->id)->get()->toArray());
        $this->assertEquals($beforeExtra, ExtraPresent::where('duty_session_id', $session->id)->get()->toArray());
    }

    public function test_closed_sessions_are_included_as_comparable_history(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $this->seedSession($event, null, now()->subMonths(1)->toDateString(), ['Dept A' => ['scheduled' => 5, 'present' => 5]], status: 'closed');

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertTrue($forecast['has_history']);
        $this->assertSame(1, $forecast['comparable_count']);
    }

    public function test_legacy_sessions_without_event_id_do_not_crash_forecasting_and_are_excluded(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        // A legacy, pre-Phase-2 session — no structured identity at all.
        DutySession::create(['name' => 'Legacy', 'date' => now()->subMonths(1)->toDateString(), 'status' => 'closed', 'miqaat' => 'Old Text']);

        $forecast = app(ForecastingService::class)->forecast($event, null);

        $this->assertFalse($forecast['has_history']); // the legacy session cannot match anything structured
    }

    // --- 30: permission boundary (forecast is only reachable via the gated wizard route) --

    public function test_forecast_section_only_reachable_through_the_permission_gated_wizard(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);

        $this->actingAs($viewer)->get(route('sessions.create', ['miqaat_id' => $miqaat->id, 'event_id' => $event->id]))
            ->assertForbidden();
    }

    // --- 31: explanation contains only supported facts -----------------------------------

    public function test_explanation_only_references_facts_the_calculation_actually_used(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->seedSession($event, $venue, now()->subMonths(1)->toDateString(), [
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 5],
        ]);
        $this->seedSession($event, $venue, now()->subMonths(2)->toDateString(), [
            'Dept B' => ['scheduled' => 0, 'present' => 0, 'extra' => 5],
        ]);

        $forecast = app(ForecastingService::class)->forecast($event, $venue);
        $text = implode(' ', $forecast['explanation']);

        $this->assertStringContainsString('comparable historical session', $text);
        $this->assertStringContainsString('Dept B', $text);
        // Never a causal claim — "caused"/"guarantees" would overstate correlation as causation.
        $this->assertStringNotContainsString('guarantee', strtolower($text));
        $this->assertStringNotContainsString(' will happen', strtolower($text));
    }

    // --- 32: full suite stays green is verified by the runner, not this file -------------

    public function test_forecast_is_visible_end_to_end_on_the_create_event_session_page(): void
    {
        $miqaat = $this->miqaat();
        $event = $this->event($miqaat);
        $venue = $this->venue();
        $this->seedSession($event, $venue, now()->subMonths(1)->toDateString(), [
            'Dept A' => ['scheduled' => 10, 'present' => 9, 'extra' => 2],
        ]);

        $response = $this->actingAs($this->admin)->get(route('sessions.create', ['miqaat_id' => $miqaat->id, 'event_id' => $event->id]));

        $response->assertOk()
            ->assertSee('Historical Planning Intelligence')
            ->assertSee('Recommended HR')
            ->assertSee('Department Planning')
            ->assertSee('Dept A');
    }
}
