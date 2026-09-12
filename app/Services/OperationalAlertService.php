<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\DutyAssignment;
use App\Models\EventPlan;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\SessionReopenEvent;
use Illuminate\Support\Carbon;

/**
 * Phase 7: Operational Alert Center. Every alert is computed live from
 * current data on every request — nothing is persisted. This is
 * deliberate: the underlying conditions (pending assignments, planning
 * gaps, import quality) change constantly during live operations, so a
 * persisted "alert" row would either go stale immediately or need its own
 * dismissal/re-trigger machinery for no real benefit. Recomputing is cheap
 * (a handful of grouped SQL aggregates) and is automatically "deduplicated"
 * by construction — the same underlying condition always produces exactly
 * one alert per scope, never a growing stream of near-duplicates.
 *
 * Every condition here is deterministic and documented with its exact
 * threshold — no ML, no scoring. Thresholds are centralized as class
 * constants so they are never scattered magic numbers across views/tests.
 *
 * TERMINOLOGY: this service has 7 detection methods below (pending,
 * planning, import, reopen, extra-present, corrections, attendance-rate)
 * — call these ALERT CATEGORIES, not "the 7 alerts". A single category
 * can emit zero, one, or several individual alerts, and can emit more
 * than one DISTINCT CONDITION: planningAlerts() alone produces both a
 * per-session-per-department "Underplanned Department" condition and a
 * separate cross-session "Consistently Underplanned Department"
 * condition. The Alert Center therefore shows a variable, data-driven
 * number of alerts — never a fixed count of seven.
 */
class OperationalAlertService
{
    public function __construct(private readonly EventPlanningService $planning) {}

    public const UNDERPLANNED_VARIANCE_THRESHOLD = 0.2; // Actual > Planned * 1.2

    public const HIGH_INVALID_IMPORT_RATIO = 0.2; // invalid_rows / total_rows >= 20%

    public const REPEATED_REOPEN_COUNT = 2; // >= 2 reopen events on one session

    public const HIGH_EXTRA_PRESENT_COUNT = 5; // >= 5 Extra Present in one session

    public const HIGH_EXTRA_PRESENT_RATIO = 0.3; // or >= 30% of that session's Present count

    public const REPEATED_CORRECTIONS_COUNT = 3; // >= 3 corrections in range (global, operational visibility only)

    public const REPEATED_UNDERPLANNED_SESSIONS = 2; // a department flagged underplanned in >= 2 sessions

    /** Attendance concern threshold, chosen after inspecting real session data (see Phase 6 Overview): below 70% on a session with a non-trivial sample is worth a look, not a statistical claim. */
    public const LOW_ATTENDANCE_RATE_PCT = 70.0;

    public const LOW_ATTENDANCE_MIN_SCHEDULED = 5; // ignore tiny sessions — one absence would otherwise swing the rate wildly

    /**
     * @return array<int,array<string,mixed>> each shaped {key,title,severity,reason,values,threshold,link}
     */
    public function detect(string $from, string $to, Carbon $utcStart, Carbon $utcEnd): array
    {
        $alerts = [];

        foreach ($this->pendingAlerts($from, $to) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->planningAlerts($from, $to) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->importAlerts($utcStart, $utcEnd) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->reopenAlerts($utcStart, $utcEnd) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->extraPresentAlerts($from, $to) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->correctionAlert($utcStart, $utcEnd) as $a) {
            $alerts[] = $a;
        }
        foreach ($this->attendanceRateAlerts($from, $to) as $a) {
            $alerts[] = $a;
        }

        usort($alerts, fn ($a, $b) => ($b['severity'] === 'high' ? 1 : 0) <=> ($a['severity'] === 'high' ? 1 : 0));

        return $alerts;
    }

    /** HIGH: an Active session still has Pending assignments. */
    private function pendingAlerts(string $from, string $to): array
    {
        $rows = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->where('duty_sessions.status', 'active')
            ->where('duty_assignments.current_status', 'pending')
            ->groupBy('duty_sessions.id', 'duty_sessions.name')
            ->selectRaw('duty_sessions.id as session_id, duty_sessions.name as session_name, COUNT(*) as pending_count')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => 'pending:'.$row->session_id,
            'title' => 'Unresolved Pending Assignments',
            'severity' => 'high',
            'reason' => "{$row->pending_count} assignment(s) in \"{$row->session_name}\" are still Pending while the session is Active.",
            'values' => ['session' => $row->session_name, 'pending' => $row->pending_count],
            'threshold' => 'Active session, current_status = pending',
            'link' => route('sessions.show', $row->session_id),
        ])->all();
    }

    /**
     * HIGH: planned = 0 & actual > 0, OR actual > planned * (1 + variance
     * threshold). MEDIUM: a department flagged underplanned in >= 2
     * sessions in range (a persistent pattern, not a one-off).
     */
    private function planningAlerts(string $from, string $to): array
    {
        $plans = EventPlan::with(['event', 'dutySession'])
            ->where('status', 'finalized')->whereNotNull('duty_session_id')
            ->whereDate('planned_date', '>=', $from)->whereDate('planned_date', '<=', $to)
            ->get();

        $alerts = [];
        $repeatCount = [];

        foreach ($plans as $plan) {
            $rows = $this->planning->planVsActualByDepartment($plan, $plan->dutySession);

            foreach ($rows as $row) {
                $missingFromPlan = $row['planned'] === 0 && $row['actual'] > 0;
                $overVariance = $row['planned'] > 0 && $row['actual'] > $row['planned'] * (1 + self::UNDERPLANNED_VARIANCE_THRESHOLD);

                if (! $missingFromPlan && ! $overVariance) {
                    continue;
                }

                $repeatCount[$row['name']] = ($repeatCount[$row['name']] ?? 0) + 1;

                $reason = $missingFromPlan
                    ? "{$row['name']} was not in the plan (Planned 0) but received {$row['actual']} actual assignment(s) in \"{$plan->event->name}\"."
                    : "{$row['name']} received {$row['actual']} actual assignment(s) against a plan of {$row['planned']} in \"{$plan->event->name}\" — more than ".(self::UNDERPLANNED_VARIANCE_THRESHOLD * 100).'% over plan.';

                $alerts[] = [
                    'key' => 'underplanned:'.$plan->id.':'.$row['department_id'],
                    'title' => 'Underplanned Department',
                    'severity' => 'high',
                    'reason' => $reason,
                    'values' => ['department' => $row['name'], 'planned' => $row['planned'], 'actual' => $row['actual'], 'gap' => $row['gap']],
                    'threshold' => $missingFromPlan ? 'Planned = 0 and Actual > 0' : 'Actual > Planned × '.(1 + self::UNDERPLANNED_VARIANCE_THRESHOLD),
                    'link' => route('analytics.planning', ['from' => $from, 'to' => $to]),
                ];
            }
        }

        foreach ($repeatCount as $name => $count) {
            if ($count >= self::REPEATED_UNDERPLANNED_SESSIONS) {
                $alerts[] = [
                    'key' => 'repeated-underplanned:'.$name,
                    'title' => 'Consistently Underplanned Department',
                    'severity' => 'medium',
                    'reason' => "{$name} was underplanned in {$count} session(s) in this period — a candidate for a higher future Recommended Scheduled HR.",
                    'values' => ['department' => $name, 'sessions' => $count],
                    'threshold' => 'Underplanned in >= '.self::REPEATED_UNDERPLANNED_SESSIONS.' sessions',
                    'link' => route('analytics.planning', ['from' => $from, 'to' => $to]),
                ];
            }
        }

        return $alerts;
    }

    /** HIGH: >= 20% of a batch's rows were invalid. */
    private function importAlerts(Carbon $utcStart, Carbon $utcEnd): array
    {
        return ImportBatch::whereBetween('created_at', [$utcStart, $utcEnd])
            ->where('total_rows', '>', 0)
            ->get()
            ->filter(fn ($b) => ($b->invalid_rows / $b->total_rows) >= self::HIGH_INVALID_IMPORT_RATIO)
            ->map(fn ($b) => [
                'key' => 'import:'.$b->id,
                'title' => 'High Invalid Import Ratio',
                'severity' => 'high',
                'reason' => "\"{$b->original_filename}\" had {$b->invalid_rows} invalid row(s) out of {$b->total_rows} (".round(100 * $b->invalid_rows / $b->total_rows).'%).',
                'values' => ['file' => $b->original_filename, 'invalid' => $b->invalid_rows, 'total' => $b->total_rows],
                'threshold' => 'invalid_rows / total_rows >= '.(self::HIGH_INVALID_IMPORT_RATIO * 100).'%',
                'link' => route('sessions.imports.diff', [$b->duty_session_id, $b->id]),
            ])->values()->all();
    }

    /** HIGH: a session reopened 2 or more times. */
    private function reopenAlerts(Carbon $utcStart, Carbon $utcEnd): array
    {
        return SessionReopenEvent::whereBetween('reopened_at', [$utcStart, $utcEnd])
            ->groupBy('duty_session_id')
            ->havingRaw('COUNT(*) >= ?', [self::REPEATED_REOPEN_COUNT])
            ->with('dutySession:id,name')
            ->selectRaw('duty_session_id, COUNT(*) as reopen_count')
            ->get()
            ->map(fn ($row) => [
                'key' => 'reopen:'.$row->duty_session_id,
                'title' => 'Repeatedly Reopened Session',
                'severity' => 'high',
                'reason' => "\"{$row->dutySession?->name}\" was reopened {$row->reopen_count} times.",
                'values' => ['session' => $row->dutySession?->name, 'reopens' => $row->reopen_count],
                'threshold' => '>= '.self::REPEATED_REOPEN_COUNT.' reopen events',
                'link' => route('audit.index'),
            ])->all();
    }

    /** HIGH: >= 5 Extra Present in one session, or >= 30% of that session's Present count. */
    private function extraPresentAlerts(string $from, string $to): array
    {
        $extraBySession = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.id', 'duty_sessions.name')
            ->selectRaw('duty_sessions.id as session_id, duty_sessions.name as session_name, COUNT(*) as extra_count')
            ->get();

        $presentBySession = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->where('duty_assignments.current_status', 'present')
            ->groupBy('duty_sessions.id')
            ->selectRaw('duty_sessions.id as session_id, COUNT(*) as present_count')
            ->pluck('present_count', 'session_id');

        return $extraBySession->filter(function ($row) use ($presentBySession) {
            $present = (int) ($presentBySession[$row->session_id] ?? 0);

            return $row->extra_count >= self::HIGH_EXTRA_PRESENT_COUNT
                || ($present > 0 && $row->extra_count / $present >= self::HIGH_EXTRA_PRESENT_RATIO);
        })->map(fn ($row) => [
            'key' => 'extra:'.$row->session_id,
            'title' => 'High Extra Present Volume',
            'severity' => 'high',
            'reason' => "\"{$row->session_name}\" recorded {$row->extra_count} Extra Present.",
            'values' => ['session' => $row->session_name, 'extra' => $row->extra_count],
            'threshold' => '>= '.self::HIGH_EXTRA_PRESENT_COUNT.' or >= '.(self::HIGH_EXTRA_PRESENT_RATIO * 100).'% of Present',
            'link' => route('sessions.show', $row->session_id),
        ])->values()->all();
    }

    /** MEDIUM: >= 3 corrections (absent-then-present on the same assignment) in range — operational visibility only, no blame attached to a name. */
    private function correctionAlert(Carbon $utcStart, Carbon $utcEnd): array
    {
        $count = AttendanceEvent::query()
            ->from('attendance_events as ae')
            ->where('ae.action', 'present')
            ->whereBetween('ae.performed_at', [$utcStart, $utcEnd])
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('attendance_events as prior')
                    ->whereColumn('prior.duty_assignment_id', 'ae.duty_assignment_id')
                    ->where('prior.action', 'absent')
                    ->whereColumn('prior.id', '<', 'ae.id');
            })
            ->count();

        if ($count < self::REPEATED_CORRECTIONS_COUNT) {
            return [];
        }

        return [[
            'key' => 'corrections:'.$utcStart->toDateString().':'.$utcEnd->toDateString(),
            'title' => 'Repeated Corrections',
            'severity' => 'medium',
            'reason' => "{$count} attendance correction(s) (Absent corrected to Present) recorded in this period.",
            'values' => ['corrections' => $count],
            'threshold' => '>= '.self::REPEATED_CORRECTIONS_COUNT.' corrections',
            'link' => route('audit.index'),
        ]];
    }

    /** MEDIUM: session attendance rate below threshold, ignoring tiny sessions where one absence swings the rate. */
    private function attendanceRateAlerts(string $from, string $to): array
    {
        $rows = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.id', 'duty_sessions.name')
            ->havingRaw('COUNT(*) >= ?', [self::LOW_ATTENDANCE_MIN_SCHEDULED])
            ->selectRaw("duty_sessions.id as session_id, duty_sessions.name as session_name, COUNT(*) as scheduled, SUM(current_status = 'present') as present")
            ->get();

        return $rows->map(function ($row) {
            $rate = round(100 * $row->present / $row->scheduled, 1);

            return $rate < self::LOW_ATTENDANCE_RATE_PCT ? [
                'key' => 'low-attendance:'.$row->session_id,
                'title' => 'Low Attendance Rate',
                'severity' => 'medium',
                'reason' => "\"{$row->session_name}\" has an attendance rate of {$rate}% ({$row->present}/{$row->scheduled}).",
                'values' => ['session' => $row->session_name, 'rate' => $rate, 'present' => $row->present, 'scheduled' => $row->scheduled],
                'threshold' => 'rate < '.self::LOW_ATTENDANCE_RATE_PCT.'%, scheduled >= '.self::LOW_ATTENDANCE_MIN_SCHEDULED,
                'link' => route('sessions.show', $row->session_id),
            ] : null;
        })->filter()->values()->all();
    }
}
