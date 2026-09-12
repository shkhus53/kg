<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\Venue;

/**
 * Phase 4: turns an approved Phase 3 forecast into an actionable staffing
 * plan. Never recomputes or overrides forecasting logic — ForecastingService
 * remains the sole calculation source (see class docblock there for the
 * non-negotiable definitions this service must not violate).
 *
 * A plan's `departments` working set is built ONCE from the forecast at
 * creation time (`buildDepartmentPlan`) — `recommended` per department is
 * copied from that snapshot and never changes again for this plan, even if
 * the underlying historical data or forecasting algorithm changes later.
 * Only `planned` is ever mutated after creation (`applyPlannedQuantities`).
 */
class EventPlanningService
{
    public function __construct(private readonly ForecastingService $forecasting) {}

    /**
     * @return array<string,mixed> the raw Phase 3 forecast, unmodified
     */
    public function previewForecast(Event $event, ?Venue $venue): array
    {
        return $this->forecasting->forecast($event, $venue);
    }

    /**
     * Builds the initial Phase 4 department working set from a forecast.
     * `planned` starts equal to `recommended` — the operator adjusts from
     * there, never from a blank slate. Extra-present-only / hidden
     * departments are included as-is (ForecastingService already unions
     * them in) — Phase 4 must never filter them back out.
     *
     * @param  array<string,mixed>  $forecast
     * @return array<int,array<string,mixed>>
     */
    public function buildDepartmentPlan(array $forecast): array
    {
        if (empty($forecast['has_history']) || empty($forecast['departments'])) {
            return [];
        }

        return array_map(fn (array $dept) => [
            'department_id' => $dept['department_id'],
            'name' => $dept['name'],
            'recommended' => $dept['recommended'],
            'planned' => $dept['recommended'],
            'expected_attendance' => $dept['expected_attendance'],
            'evidence' => $dept['evidence'],
            'extra_present_only' => $dept['extra_present_only'],
            'tags' => $dept['tags'],
        ], $forecast['departments']);
    }

    /**
     * Applies operator-submitted planned quantities onto an existing
     * department working set. `recommended` is untouched — only `planned`
     * moves. Departments not present in $plannedByDeptId keep their
     * current planned value (a partial submit never zeroes the rest).
     *
     * @param  array<int,array<string,mixed>>  $departments
     * @param  array<int|string,int>  $plannedByDeptId
     * @return array<int,array<string,mixed>>
     */
    public function applyPlannedQuantities(array $departments, array $plannedByDeptId): array
    {
        return array_map(function (array $dept) use ($plannedByDeptId) {
            if (array_key_exists($dept['department_id'], $plannedByDeptId)) {
                $dept['planned'] = max(0, (int) $plannedByDeptId[$dept['department_id']]);
            }

            return $dept;
        }, $departments);
    }

    /**
     * @param  array<int,array<string,mixed>>  $departments
     */
    public function plannedTotal(array $departments): int
    {
        return array_sum(array_column($departments, 'planned'));
    }

    /**
     * Decorates each department row with its plan-vs-recommendation
     * classification (Phase 4's own status, distinct from Phase 3's
     * historical evidence tags — both are shown, never merged).
     *
     * @param  array<int,array<string,mixed>>  $departments
     * @return array<int,array<string,mixed>>
     */
    public function withPlanningStatus(array $departments): array
    {
        return array_map(fn (array $dept) => [
            ...$dept,
            'planning_status' => EventPlan::classify($dept['planned'], $dept['recommended']),
        ], $departments);
    }

    /**
     * Plan (recommended/planned) vs Actual per department, once the linked
     * DutySession has an imported duty list. "Actual" counts DutyAssignment
     * ROWS per department_id — the same ITS scheduled in three departments
     * is three assignments and must count as 3, once per department, never
     * collapsed to 1 by person identity. This never touches attendance
     * (Present/Absent) or Extra Present — those stay separate concepts.
     *
     * @return array<int,array{department_id:int,name:string,planned:int,actual:int,gap:int}>
     */
    public function planVsActualByDepartment(EventPlan $plan, DutySession $session): array
    {
        $actualByDept = DutyAssignment::where('duty_session_id', $session->id)
            ->selectRaw('department_id, COUNT(*) as actual_count')
            ->groupBy('department_id')
            ->pluck('actual_count', 'department_id');

        $rows = [];
        $seenDeptIds = [];

        foreach ($plan->departments ?? [] as $dept) {
            $deptId = $dept['department_id'];
            $seenDeptIds[$deptId] = true;
            $actual = (int) ($actualByDept[$deptId] ?? 0);

            $rows[] = [
                'department_id' => $deptId,
                'name' => $dept['name'],
                'planned' => $dept['planned'],
                'actual' => $actual,
                'gap' => $actual - $dept['planned'],
            ];
        }

        // A department actually assigned via the duty list but never part of
        // the plan (e.g. added ad-hoc during import) still gets surfaced —
        // Planned=0 is a fact, not an omission to hide.
        foreach ($actualByDept as $deptId => $count) {
            if (isset($seenDeptIds[$deptId])) {
                continue;
            }

            $rows[] = [
                'department_id' => $deptId,
                'name' => Department::find($deptId)?->name ?? 'Unknown Department',
                'planned' => 0,
                'actual' => (int) $count,
                'gap' => (int) $count,
            ];
        }

        return $rows;
    }

    /**
     * Import Center 2.0: Plan vs the file currently being previewed, BEFORE
     * commit. Read-only — never writes to the plan or its snapshot. Matches
     * by normalized department name (import rows carry a raw venue_name
     * string, not yet a department_id for brand-new departments).
     *
     * @param  array<int,array<string,mixed>>  $planDepartments
     * @param  array<string,int>  $departmentCountsByName  raw venue_name => count of NEW valid assignment rows in this file
     * @return array<int,array{name:string,planned:int,incoming:int,gap:int}>
     */
    public function planVsIncoming(array $planDepartments, array $departmentCountsByName): array
    {
        $incomingByNormalizedKey = [];
        foreach ($departmentCountsByName as $name => $count) {
            $incomingByNormalizedKey[Department::normalize($name)] = $count;
        }

        $rows = [];
        foreach ($planDepartments as $dept) {
            $key = Department::normalize($dept['name']);
            $incoming = $incomingByNormalizedKey[$key] ?? 0;

            $rows[] = [
                'name' => $dept['name'],
                'planned' => $dept['planned'],
                'incoming' => $incoming,
                'gap' => $incoming - $dept['planned'],
            ];
        }

        return $rows;
    }
}
