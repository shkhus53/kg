<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Venue;
use App\Support\WeightedStats;
use Illuminate\Support\Collection;

/**
 * Phase 3: turns HistoricalDemandService's weighted historical facts into a
 * forecast an Admin can act on. Deterministic/statistical only — no ML.
 *
 * NON-NEGOTIABLE definitions, enforced throughout:
 *   Operational Demand      = Present + Extra Present   (never + Scheduled)
 *   Recommended Scheduled HR = derived FROM department recommendations,
 *                              never computed independently of them —
 *                              SUM(department recommended) === headline,
 *                              always, by construction (see recommend()).
 *   Expected Attendance      is Present alone — never conflated with demand.
 *   Extra Present is never a "future staffing bucket" — a department that
 *   is Extra-Present-only in history becomes an ordinary recommended
 *   department for the future, nothing more, nothing labeled "extras".
 */
class ForecastingService
{
    public function __construct(private readonly HistoricalDemandService $historical) {}

    /**
     * @return array<string,mixed>
     */
    public function forecast(Event $event, ?Venue $venue): array
    {
        $comparable = $this->historical->comparableSessions($event, $venue);
        $sessions = $comparable['sessions'];

        if ($sessions->isEmpty()) {
            return [
                'has_history' => false,
                'tier' => 'none',
                'comparable_count' => 0,
                'message' => 'No historical data available for a reliable recommendation.',
            ];
        }

        $sameEventCount = $sessions->where('tier', 'same_event')->count();
        $confidence = $this->confidence($sessions);

        $departmentRows = $this->historical->departmentDemand($sessions);

        [$departmentRecommendations, $headline, $rawTotal] = $this->recommend($departmentRows);

        $demandValues = $sessions->pluck('demand')->map(fn ($v) => (float) $v)->all();
        $weights = $sessions->pluck('weight')->all();
        $dampedDemand = WeightedStats::dampOutliers($demandValues, $weights);

        $range = $this->range($headline, $dampedDemand, $weights, $sessions->count());

        $presentValues = $sessions->pluck('present')->map(fn ($v) => (float) $v)->all();
        $expectedAttendance = (int) round(WeightedStats::weightedMean(
            WeightedStats::dampOutliers($presentValues, $weights),
            $weights
        ));

        return [
            'has_history' => true,
            'tier' => $comparable['tier'],
            'comparable_count' => $sessions->count(),
            'same_event_count' => $sameEventCount,
            'recommended_scheduled_hr' => $headline,
            'expected_attendance' => $expectedAttendance,
            'operational_demand' => (int) round($rawTotal),
            'range' => $range,
            'confidence' => $confidence,
            'departments' => $departmentRecommendations,
            'planning_gap' => $this->planningGapSummary($sessions),
            'explanation' => $this->explain($event, $venue, $comparable['tier'], $sessions, $departmentRecommendations, $headline),
            'evidence_sessions' => $sessions->sortByDesc('date')->values()->all(),
        ];
    }

    /**
     * Confidence reflects the evidence actually used, not merely whether
     * that evidence happened to be same-event. "0 same-event sessions"
     * must never by itself mean "no recommendation" — it only means the
     * strongest possible tier wasn't available; a recommendation still
     * exists (at reduced confidence) as long as SOME comparable tier has
     * meaningful evidence. "No recommendation" is reserved for genuinely
     * empty history, which forecast() already handles before this is ever
     * called (kept here as a defensive fallback, not the primary path).
     *
     * @param  Collection<int,array<string,mixed>>  $sessions
     */
    private function confidence($sessions): string
    {
        if ($sessions->isEmpty()) {
            return 'Insufficient Data';
        }

        $sameEvent = $sessions->where('tier', 'same_event')->count();
        if ($sameEvent >= 4) {
            return 'High';
        }
        if ($sameEvent >= 2) {
            return 'Medium';
        }
        if ($sameEvent === 1) {
            return 'Low';
        }

        // No same-event evidence at all — fall back to judging the
        // broader tiers actually present, rather than treating "0
        // same-event" as if it meant "no evidence whatsoever".
        $sameFamilySameMiqaat = $sessions->where('tier', 'same_family_same_miqaat')->count();
        if ($sameFamilySameMiqaat >= 4) {
            return 'Medium';
        }
        if ($sameFamilySameMiqaat >= 1) {
            return 'Low';
        }

        $sameFamilyCrossMiqaat = $sessions->where('tier', 'same_family_cross_miqaat')->count();
        if ($sameFamilyCrossMiqaat >= 1) {
            return 'Low';
        }

        return 'Insufficient Data';
    }

    /**
     * Department recommendations are computed FIRST (each with its own
     * outlier damping against its own historical rows), summed for the raw
     * headline, rounded up to the nearest 5 for the presented headline,
     * then apportioned via largest-remainder so the integer department
     * allocations sum to EXACTLY that rounded headline. The headline is
     * derived from departments, never the other way around.
     *
     * @param  array<int,array{department_id:int,name:string,rows:array<int,array<string,mixed>>}>  $departmentRows
     * @return array{0: array<int,array<string,mixed>>, 1: int, 2: float}
     */
    private function recommend(array $departmentRows): array
    {
        if (empty($departmentRows)) {
            return [[], 0, 0.0];
        }

        $rawShares = [];
        $meta = [];

        foreach ($departmentRows as $dept) {
            $rows = $dept['rows'];
            $demandValues = array_map(fn ($r) => (float) $r['demand'], $rows);
            $weights = array_map(fn ($r) => (float) $r['weight'], $rows);
            $damped = WeightedStats::dampOutliers($demandValues, $weights);

            $raw = WeightedStats::weightedMean($damped, $weights);
            $rawShares[$dept['department_id']] = $raw;

            $presentValues = array_map(fn ($r) => (float) $r['present'], $rows);
            $expectedAttendance = (int) round(WeightedStats::weightedMean(
                WeightedStats::dampOutliers($presentValues, $weights),
                $weights
            ));

            $evidenceCount = count($rows);
            $avgScheduled = WeightedStats::weightedMean(array_map(fn ($r) => (float) $r['scheduled'], $rows), $weights);
            $avgExtra = WeightedStats::weightedMean(array_map(fn ($r) => (float) $r['extra'], $rows), $weights);

            $meta[$dept['department_id']] = [
                'department_id' => $dept['department_id'],
                'name' => $dept['name'],
                'expected_attendance' => $expectedAttendance,
                'evidence_count' => $evidenceCount,
                'evidence' => $evidenceCount >= 4 ? 'Strong' : ($evidenceCount >= 2 ? 'Medium' : 'Sparse'),
                'extra_present_only' => $avgScheduled < 0.5 && $avgExtra > 0,
                'tags' => $this->departmentTags($rows),
            ];
        }

        $rawTotal = array_sum($rawShares);
        $headline = $rawTotal > 0 ? (int) (ceil($rawTotal / 5) * 5) : 0;

        $apportioned = WeightedStats::apportion($rawShares, $headline);

        $departments = [];
        foreach ($meta as $deptId => $info) {
            $departments[] = [...$info, 'recommended' => $apportioned[$deptId] ?? 0];
        }

        // Largest recommendation first — the primary planning output should
        // read top-down by staffing weight, not by database id order.
        usort($departments, fn ($a, $b) => $b['recommended'] <=> $a['recommended']);

        return [$departments, $headline, $rawTotal];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function departmentTags(array $rows): array
    {
        $tags = [];
        $n = count($rows);

        $avgScheduled = array_sum(array_column($rows, 'scheduled')) / $n;
        $avgExtra = array_sum(array_column($rows, 'extra')) / $n;

        if ($avgScheduled < 0.5 && $avgExtra > 0) {
            $tags[] = 'extra_present_only';
        }

        if ($n < 2) {
            $tags[] = 'sparse_evidence';

            return $tags; // not enough rows for the ratio-based tags below to mean anything
        }

        $withScheduled = array_filter($rows, fn ($r) => $r['scheduled'] > 0);
        if (count($withScheduled) >= 2) {
            $underPlannedCount = count(array_filter($withScheduled, fn ($r) => $r['demand'] > $r['scheduled']));
            $overPlannedCount = count(array_filter($withScheduled, fn ($r) => $r['demand'] < $r['scheduled']));
            $total = count($withScheduled);

            if ($underPlannedCount / $total >= 0.6) {
                $tags[] = 'consistently_under_planned';
            } elseif ($overPlannedCount / $total >= 0.6) {
                $tags[] = 'over_planned';
            }
        }

        if ($n >= 4) {
            usort($rows, fn ($a, $b) => $a['session_id'] <=> $b['session_id']);
            $half = intdiv($n, 2);
            $older = array_slice($rows, 0, $half);
            $recent = array_slice($rows, $half);
            $olderAvg = array_sum(array_column($older, 'demand')) / count($older);
            $recentAvg = array_sum(array_column($recent, 'demand')) / count($recent);

            if ($olderAvg > 0 && $recentAvg > $olderAvg * 1.15) {
                $tags[] = 'growing_demand';
            }
        }

        return $tags;
    }

    /**
     * @param  array<int,float>  $dampedDemand
     * @param  array<int,float>  $weights
     * @return array{low:int,high:int}
     */
    private function range(int $headline, array $dampedDemand, array $weights, int $comparableCount): array
    {
        if ($comparableCount === 1) {
            return [
                'low' => (int) round($headline * 0.9),
                'high' => (int) round($headline * 1.1),
            ];
        }

        $stdDev = WeightedStats::weightedStdDev($dampedDemand, $weights);

        return [
            'low' => max(0, (int) round($headline - $stdDev)),
            'high' => (int) round($headline + $stdDev),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $sessions
     * @return array{under_planning_pct:?float,over_planning_pct:?float,extra_dependency_pct:?float}
     */
    private function planningGapSummary($sessions): array
    {
        $underRatios = [];
        $overRatios = [];
        $extraRatios = [];
        $weights = [];

        foreach ($sessions as $s) {
            if ($s['scheduled'] > 0) {
                $gapPct = max(0, $s['planning_gap']) / $s['scheduled'] * 100;
                $underRatios[] = $gapPct;
                $overRatios[] = max(0, -$s['planning_gap']) / $s['scheduled'] * 100;
                $weights[] = $s['weight'];
            }
        }

        $extraWeights = [];
        foreach ($sessions as $s) {
            if ($s['demand'] > 0) {
                $extraRatios[] = $s['extra'] / $s['demand'] * 100;
                $extraWeights[] = $s['weight'];
            }
        }

        return [
            'under_planning_pct' => $underRatios ? round(WeightedStats::weightedMean($underRatios, $weights), 1) : null,
            'over_planning_pct' => $overRatios ? round(WeightedStats::weightedMean($overRatios, $weights), 1) : null,
            'extra_dependency_pct' => $extraRatios ? round(WeightedStats::weightedMean($extraRatios, $extraWeights), 1) : null,
        ];
    }

    /**
     * Every sentence here must be a fact this same calculation actually
     * used — no causal claims, no numbers the arithmetic above didn't
     * produce.
     *
     * @param  Collection<int,array<string,mixed>>  $sessions
     * @param  array<int,array<string,mixed>>  $departments
     * @return array<int,string>
     */
    private function explain(Event $event, ?Venue $venue, string $tier, $sessions, array $departments, int $headline): array
    {
        $lines = [];
        $sameEvent = $sessions->where('tier', 'same_event')->count();
        $sameFamilySameMiqaat = $sessions->where('tier', 'same_family_same_miqaat')->count();
        $sameFamilyCrossMiqaat = $sessions->where('tier', 'same_family_cross_miqaat')->count();

        if ($sameEvent > 0) {
            $lines[] = "Based on {$sameEvent} comparable historical session(s) for this exact event.";
        } else {
            $lines[] = 'This exact event has no prior recorded session — the recommendation instead uses related-event evidence (see below), at reduced confidence.';
        }

        if ($sameFamilySameMiqaat > 0) {
            $lines[] = "{$sameFamilySameMiqaat} session(s) from the same Event Family within the same Miqaat were included".($sameEvent > 0 ? ' because same-event history alone was limited.' : '.');
        }

        if ($sameFamilyCrossMiqaat > 0) {
            $lines[] = "{$sameFamilyCrossMiqaat} session(s) from the same Event Family in a different Miqaat were included as broader fallback evidence.";
        }

        $mostRecent = $sessions->sortByDesc('date')->first();
        if ($mostRecent) {
            $lines[] = 'Most recent comparable session ('.$mostRecent['date'].') recorded an operational demand of '.$mostRecent['demand'].'.';
        }

        if ($venue) {
            $venueMatches = $sessions->where('venue_match', true)->count();
            if ($venueMatches > 0) {
                $lines[] = "{$venueMatches} of these session(s) were at the same venue, increasing their relevance.";
            }
        }

        foreach ($departments as $dept) {
            if (in_array('extra_present_only', $dept['tags'], true)) {
                $lines[] = "{$dept['name']} historically required staffing only through Extra Present — now included as a recommended department, not a future \"extra\" category.";
            }
            if (in_array('consistently_under_planned', $dept['tags'], true)) {
                $lines[] = "{$dept['name']} was consistently under-planned in prior sessions (actual demand exceeded the scheduled list).";
            }
        }

        return $lines;
    }
}
