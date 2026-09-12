<?php

namespace App\Services;

use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Event;
use App\Models\ExtraPresent;
use App\Models\Venue;
use App\Support\WeightedStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 3: gathers comparable historical DutySessions for a target
 * Event/Venue and computes the weighted facts forecasting needs — nothing
 * here decides a recommendation, that is ForecastingService's job. This
 * class only ever reads (AttendanceEvent/ExtraPresent/DutyAssignment are
 * never written to from here).
 *
 * Canonical formula, non-negotiable throughout this class:
 *   Operational Demand = Present + Extra Present   (never Scheduled + Extra)
 */
class HistoricalDemandService
{
    private const RECENCY_HALF_LIFE_MONTHS = 24;

    /** Comparable sessions require at least this many before a broader tier is skipped. */
    private const MIN_COMPARABLES_BEFORE_WIDENING = 2;

    /**
     * Bucket a target Event/Venue's comparable history by evidence tier.
     * Venue never fragments Event identity (a session on the same Event at
     * a different Venue is still "same event" evidence) — it only adjusts
     * that session's weight. Widening to a broader tier (same Event Family)
     * only happens when the same-event sample is too thin to be useful.
     *
     * @return array{tier: string, sessions: Collection<int, array<string,mixed>>}
     */
    public function comparableSessions(Event $event, ?Venue $venue): array
    {
        $sameEvent = $this->sessionsForEventIds([$event->id], $event, $venue, 'same_event');

        if ($sameEvent->count() >= self::MIN_COMPARABLES_BEFORE_WIDENING) {
            return ['tier' => 'same_event', 'sessions' => $sameEvent];
        }

        $familyEventIdsSameMiqaat = $event->family
            ? Event::where('miqaat_id', $event->miqaat_id)
                ->where('family', $event->family)
                ->where('id', '!=', $event->id)
                ->pluck('id')
            : collect();

        $sameFamilySameMiqaat = $familyEventIdsSameMiqaat->isNotEmpty()
            ? $this->sessionsForEventIds($familyEventIdsSameMiqaat->all(), $event, $venue, 'same_family_same_miqaat')
            : collect();

        if ($sameFamilySameMiqaat->isNotEmpty()) {
            return [
                'tier' => $sameEvent->isEmpty() ? 'same_family_same_miqaat' : 'mixed_same_event_and_family',
                'sessions' => $sameEvent->concat($sameFamilySameMiqaat),
            ];
        }

        // No same-miqaat family match to widen with — try cross-Miqaat
        // family history, but only ADD it, never let it replace same-event
        // evidence that already exists.
        $familyEventIdsCrossMiqaat = $event->family
            ? Event::where('family', $event->family)
                ->where('miqaat_id', '!=', $event->miqaat_id)
                ->pluck('id')
            : collect();

        $crossMiqaat = $familyEventIdsCrossMiqaat->isNotEmpty()
            ? $this->sessionsForEventIds($familyEventIdsCrossMiqaat->all(), $event, $venue, 'same_family_cross_miqaat')
            : collect();

        if ($crossMiqaat->isEmpty()) {
            if ($sameEvent->isNotEmpty()) {
                return ['tier' => 'same_event', 'sessions' => $sameEvent];
            }

            return ['tier' => 'none', 'sessions' => collect()];
        }

        if ($sameEvent->isEmpty()) {
            return ['tier' => 'same_family_cross_miqaat', 'sessions' => $crossMiqaat];
        }

        return ['tier' => 'mixed', 'sessions' => $sameEvent->concat($crossMiqaat)];
    }

    /**
     * @param  array<int,int>  $eventIds
     * @return Collection<int, array<string,mixed>>
     */
    private function sessionsForEventIds(array $eventIds, Event $targetEvent, ?Venue $targetVenue, string $tierLabel): Collection
    {
        $sessions = DutySession::whereIn('event_id', $eventIds)->get(['id', 'date', 'venue_id', 'event_id']);

        if ($sessions->isEmpty()) {
            return collect();
        }

        $sessionIds = $sessions->pluck('id');
        $scheduledPresent = $this->sessionScheduledAndPresent($sessionIds);
        $extra = $this->sessionExtraPresent($sessionIds);

        $today = Carbon::today();
        $tierWeight = match ($tierLabel) {
            'same_event' => 1.0,
            'same_family_same_miqaat' => 0.6,
            'same_family_cross_miqaat' => 0.4,
            default => 1.0,
        };

        return $sessions->map(function (DutySession $session) use ($scheduledPresent, $extra, $today, $tierWeight, $targetVenue, $tierLabel) {
            $scheduled = $scheduledPresent[$session->id]['scheduled'] ?? 0;
            $present = $scheduledPresent[$session->id]['present'] ?? 0;
            $extraCount = $extra[$session->id] ?? 0;
            $demand = $present + $extraCount;

            $monthsAgo = $session->date->diffInDays($today) / 30.44;
            $recency = WeightedStats::recencyWeight($monthsAgo);

            $venueMatch = $targetVenue && $session->venue_id === $targetVenue->id;
            $venueMultiplier = $tierLabel === 'same_event' ? ($venueMatch ? 1.0 : 0.85) : 1.0;

            return [
                'session_id' => $session->id,
                'date' => $session->date->toDateString(),
                'venue_id' => $session->venue_id,
                'venue_match' => $venueMatch,
                'tier' => $tierLabel,
                'scheduled' => $scheduled,
                'present' => $present,
                'extra' => $extraCount,
                'demand' => $demand,
                'planning_gap' => $demand - $scheduled,
                'weight' => $recency * $tierWeight * $venueMultiplier,
            ];
        })->values();
    }

    /**
     * @param  Collection<int,int>  $sessionIds
     * @return array<int,array{scheduled:int,present:int}>
     */
    private function sessionScheduledAndPresent(Collection $sessionIds): array
    {
        return DutyAssignment::whereIn('duty_session_id', $sessionIds)
            ->selectRaw('duty_session_id, COUNT(*) as scheduled, SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END) as present', ['present'])
            ->groupBy('duty_session_id')
            ->get()
            ->keyBy('duty_session_id')
            ->map(fn ($row) => ['scheduled' => (int) $row->scheduled, 'present' => (int) $row->present])
            ->all();
    }

    /**
     * @param  Collection<int,int>  $sessionIds
     * @return array<int,int>
     */
    private function sessionExtraPresent(Collection $sessionIds): array
    {
        return ExtraPresent::whereIn('duty_session_id', $sessionIds)
            ->selectRaw('duty_session_id, COUNT(*) as extra_count')
            ->groupBy('duty_session_id')
            ->pluck('extra_count', 'duty_session_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Department-level demand for a given set of comparable sessions
     * (already weighted by comparableSessions()). The department universe
     * is the UNION of departments appearing in duty_assignments OR
     * extra_presents — a department with Scheduled=0 but Extra>0 must
     * never be silently dropped; that is the entire point of Rule 16.
     *
     * @param  Collection<int,array<string,mixed>>  $sessions  the 'sessions' collection from comparableSessions()
     * @return array<int,array{department_id:int,name:string,rows:array<int,array<string,mixed>>}>
     */
    public function departmentDemand(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $sessionIds = $sessions->pluck('session_id');
        $weightBySession = $sessions->pluck('weight', 'session_id');

        $assignmentRows = DutyAssignment::whereIn('duty_session_id', $sessionIds)
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id')
            ->selectRaw('duty_assignments.duty_session_id, duty_assignments.department_id, departments.name,
                COUNT(*) as scheduled, SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END) as present', ['present'])
            ->groupBy('duty_assignments.duty_session_id', 'duty_assignments.department_id', 'departments.name')
            ->get();

        $extraRows = ExtraPresent::whereIn('duty_session_id', $sessionIds)
            ->selectRaw('duty_session_id, department_id, department_name_snapshot, COUNT(*) as extra_count')
            ->groupBy('duty_session_id', 'department_id', 'department_name_snapshot')
            ->get();

        $byDepartment = [];

        foreach ($assignmentRows as $row) {
            $byDepartment[$row->department_id]['name'] = $row->name;
            $byDepartment[$row->department_id]['rows'][$row->duty_session_id] = [
                'session_id' => $row->duty_session_id,
                'scheduled' => (int) $row->scheduled,
                'present' => (int) $row->present,
                'extra' => 0,
                'weight' => (float) ($weightBySession[$row->duty_session_id] ?? 0),
            ];
        }

        foreach ($extraRows as $row) {
            $byDepartment[$row->department_id]['name'] ??= $row->department_name_snapshot;
            if (! isset($byDepartment[$row->department_id]['rows'][$row->duty_session_id])) {
                $byDepartment[$row->department_id]['rows'][$row->duty_session_id] = [
                    'session_id' => $row->duty_session_id,
                    'scheduled' => 0,
                    'present' => 0,
                    'extra' => 0,
                    'weight' => (float) ($weightBySession[$row->duty_session_id] ?? 0),
                ];
            }
            $byDepartment[$row->department_id]['rows'][$row->duty_session_id]['extra'] = (int) $row->extra_count;
        }

        $result = [];
        foreach ($byDepartment as $deptId => $data) {
            $rows = array_map(function ($row) {
                $row['demand'] = $row['present'] + $row['extra'];

                return $row;
            }, array_values($data['rows']));

            $result[] = [
                'department_id' => $deptId,
                'name' => $data['name'],
                'rows' => $rows,
            ];
        }

        return $result;
    }
}
