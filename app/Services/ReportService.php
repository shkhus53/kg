<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Support\Gender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single source of truth for report data, reused identically by the preview
 * screen, PDF export, and Excel export — so all three can never disagree.
 *
 * Counting unit for scheduled attendance is always duty_assignments (never
 * attendance_events, never import rows, never distinct ITS). current_status
 * is the sole attendance source — source Status/Scanned are never read.
 */
class ReportService
{
    /**
     * Report 1 + Report 4 combined: one Duty Session's full submission —
     * summary, department breakdown, assignment-level detail, Extra Present.
     * The spec's Report 1 and Report 4 differ only in that 4 adds a
     * department summary section; since Report 1 already needed department
     * context to be a useful "official submission" document, both are
     * served by this single structure rather than two near-duplicate
     * report types.
     */
    public function sessionReport(DutySession $dutySession): array
    {
        $counters = $this->sessionCounters($dutySession);

        $assignments = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->with(['khidmatguzar:id,its_id,full_name', 'department:id,name', 'attendanceMarkedBy:id,name'])
            ->orderBy('department_id')
            ->orderBy('id')
            ->get();

        $extraPresents = ExtraPresent::where('duty_session_id', $dutySession->id)
            ->with(['khidmatguzar:id,its_id,full_name', 'markedBy:id,name'])
            ->orderBy('marked_at')
            ->get();

        return [
            'dutySession' => $dutySession,
            ...$counters,
            'extraCount' => $extraPresents->count(),
            'departments' => $this->departmentBreakdown(sessionId: $dutySession->id),
            'assignments' => $assignments,
            'extraPresents' => $extraPresents,
        ];
    }

    /**
     * Scalar counters only (Scheduled/Present/Absent/Pending/rate/gender) —
     * no assignment/extra-present hydration. Extracted so callers that only
     * need the numbers (e.g. a polling dashboard) never pay for loading
     * every assignment row just to compute a percentage. Present ÷
     * Scheduled × 100 stays defined in exactly one place; Extra Present is
     * never part of the denominator.
     *
     * @return array{scheduled:int,present:int,absent:int,pending:int,rate:?float,genderBreakdown:array}
     */
    public function sessionCounters(DutySession $dutySession): array
    {
        $stats = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->selectRaw("
                COUNT(*) as scheduled,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                ".$this->genderSelectRaw('gender_snapshot').'
            ')->first();

        $scheduled = (int) ($stats->scheduled ?? 0);
        $present = (int) ($stats->present ?? 0);

        return [
            'scheduled' => $scheduled,
            'present' => $present,
            'absent' => (int) ($stats->absent ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null,
            'genderBreakdown' => $this->genderBreakdownFromRow($stats),
        ];
    }

    /**
     * Lightweight gender split for one session (Scheduled/Present/Absent/Pending
     * only, no assignment/extra-present hydration) — for places like the
     * Dashboard that only need the counters, not the full session report.
     */
    public function sessionGenderSummary(DutySession $dutySession): array
    {
        $stats = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->selectRaw($this->genderSelectRaw('gender_snapshot'))
            ->first();

        return $this->genderBreakdownFromRow($stats);
    }

    /**
     * Report 2: Department report, scoped by date range and/or a specific
     * session.
     */
    public function departmentReport(string $from, string $to, ?int $sessionId): array
    {
        $query = DutyAssignment::query();
        $extraQuery = ExtraPresent::query();

        if ($sessionId) {
            $query->where('duty_session_id', $sessionId);
            $extraQuery->where('duty_session_id', $sessionId);
        } else {
            $query->whereHas('dutySession', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to));
            $extraQuery->whereHas('dutySession', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to));
        }

        $stats = (clone $query)->selectRaw("
            COUNT(*) as scheduled,
            SUM(current_status = 'present') as present,
            SUM(current_status = 'absent') as absent,
            SUM(current_status = 'pending') as pending,
            ".$this->genderSelectRaw('gender_snapshot').'
        ')->first();

        $scheduled = (int) ($stats->scheduled ?? 0);
        $present = (int) ($stats->present ?? 0);

        $assignments = (clone $query)
            ->with(['khidmatguzar:id,its_id,full_name', 'department:id,name', 'dutySession:id,name,date', 'attendanceMarkedBy:id,name'])
            ->orderBy('department_id')
            ->orderBy('id')
            ->get();

        $extraPresents = (clone $extraQuery)
            ->with(['khidmatguzar:id,its_id,full_name', 'markedBy:id,name'])
            ->orderBy('marked_at')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'sessionId' => $sessionId,
            'session' => $sessionId ? DutySession::find($sessionId) : null,
            'scheduled' => $scheduled,
            'present' => $present,
            'absent' => (int) ($stats->absent ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'extraCount' => $extraPresents->count(),
            'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null,
            'genderBreakdown' => $this->genderBreakdownFromRow($stats),
            'departments' => $this->departmentBreakdown($from, $to, $sessionId),
            'assignments' => $assignments,
            'extraPresents' => $extraPresents,
        ];
    }

    /**
     * Report 3: one Khidmatguzar's full historical attendance.
     */
    public function khidmatguzarReport(Khidmatguzar $khidmatguzar): array
    {
        $stats = DutyAssignment::where('khidmatguzar_id', $khidmatguzar->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                ".$this->genderSelectRaw('gender_snapshot').'
            ')->first();

        $total = (int) ($stats->total ?? 0);
        $present = (int) ($stats->present ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $pending = (int) ($stats->pending ?? 0);
        $genderBreakdown = $this->genderBreakdownFromRow($stats);

        $departmentBreakdown = DutyAssignment::where('duty_assignments.khidmatguzar_id', $khidmatguzar->id)
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->selectRaw("
                departments.name as department_name,
                COUNT(*) as duties,
                SUM(duty_assignments.current_status = 'present') as present,
                SUM(duty_assignments.current_status = 'absent') as absent
            ")
            ->get()
            ->map(function ($row) {
                $row->rate = $row->duties > 0 ? round(100 * $row->present / $row->duties, 1) : 0;

                return $row;
            });

        $history = DutyAssignment::where('khidmatguzar_id', $khidmatguzar->id)
            ->with(['dutySession:id,name,date,status', 'department:id,name'])
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->orderByDesc('duty_sessions.date')
            ->orderByDesc('duty_assignments.id')
            ->select('duty_assignments.*')
            ->get();

        $extraHistory = ExtraPresent::where('khidmatguzar_id', $khidmatguzar->id)
            ->with(['dutySession:id,name,date'])
            ->orderByDesc('marked_at')
            ->get();

        return [
            'khidmatguzar' => $khidmatguzar,
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'pending' => $pending,
            'extraCount' => $extraHistory->count(),
            'rate' => $total > 0 ? round(100 * $present / $total, 1) : null,
            'genderBreakdown' => $genderBreakdown,
            'departmentBreakdown' => $departmentBreakdown,
            'history' => $history,
            'extraHistory' => $extraHistory,
        ];
    }

    /**
     * Full per-department detail report — KPI summary, gender breakdown,
     * member-level attendance rows, and Extra Present, for one or more
     * specific departments. Distinct from departmentReport()/departmentBreakdown()
     * (the existing all-departments summary list), which this does not
     * replace or alter. Each department gets its own independent section —
     * scheduled/present/absent/pending and gender counts are computed
     * per-department, never merged across departments.
     *
     * @param  array<int>  $departmentIds
     */
    public function departmentDetailReport(array $departmentIds, string $from, string $to, ?int $sessionId): array
    {
        $departments = Department::whereIn('id', $departmentIds)->orderBy('name')->get();

        $assignmentQuery = DutyAssignment::whereIn('department_id', $departmentIds);
        $extraQuery = ExtraPresent::whereIn('department_id', $departmentIds);

        if ($sessionId) {
            $assignmentQuery->where('duty_session_id', $sessionId);
            $extraQuery->where('duty_session_id', $sessionId);
        } else {
            $assignmentQuery->whereHas('dutySession', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to));
            $extraQuery->whereHas('dutySession', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to));
        }

        // One grouped aggregate query for every requested department's
        // stats, instead of one query per department in a loop.
        $statsByDepartment = (clone $assignmentQuery)
            ->groupBy('department_id')
            ->selectRaw("
                department_id,
                COUNT(*) as scheduled,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                ".$this->genderSelectRaw('gender_snapshot').'
            ')
            ->get()
            ->keyBy('department_id');

        // One bulk fetch for every department's assignment/extra-present
        // rows, grouped in PHP — instead of one query per department.
        $assignmentsByDepartment = (clone $assignmentQuery)
            ->with(['khidmatguzar:id,its_id,full_name', 'dutySession:id,name,date', 'attendanceMarkedBy:id,name'])
            ->orderBy('duty_session_id')
            ->orderBy('id')
            ->get()
            ->groupBy('department_id');

        $extraPresentsByDepartment = (clone $extraQuery)
            ->with(['khidmatguzar:id,its_id,full_name', 'markedBy:id,name'])
            ->orderBy('marked_at')
            ->get()
            ->groupBy('department_id');

        $sections = $departments->map(function (Department $department) use ($statsByDepartment, $assignmentsByDepartment, $extraPresentsByDepartment) {
            $stats = $statsByDepartment->get($department->id);
            $extraPresents = $extraPresentsByDepartment->get($department->id, collect());

            $scheduled = (int) ($stats->scheduled ?? 0);
            $present = (int) ($stats->present ?? 0);

            return [
                'department' => $department,
                'scheduled' => $scheduled,
                'present' => $present,
                'absent' => (int) ($stats->absent ?? 0),
                'pending' => (int) ($stats->pending ?? 0),
                'extraCount' => $extraPresents->count(),
                'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null,
                'genderBreakdown' => $stats ? $this->genderBreakdownFromRow($stats) : $this->genderBreakdownFromRow((object) []),
                'assignments' => $assignmentsByDepartment->get($department->id, collect()),
                'extraPresents' => $extraPresents,
            ];
        });

        return [
            'from' => $from,
            'to' => $to,
            'sessionId' => $sessionId,
            'session' => $sessionId ? DutySession::find($sessionId) : null,
            'sections' => $sections,
        ];
    }

    /**
     * Operator Activity report — the same query the on-screen Operator
     * Analytics page (AnalyticsController::operators) uses, moved here so
     * that screen and this report's PDF/Excel export can never disagree.
     * Operational workload only, not a leaderboard — no ranking is implied
     * by the data itself, only by how a caller chooses to sort/display it.
     *
     * @return array{from:string,to:string,operators:Collection}
     */
    public function operatorActivityReport(string $from, string $to): array
    {
        $attendanceStats = AttendanceEvent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'attendance_events.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)
            ->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('attendance_events.performed_by')
            ->selectRaw("
                attendance_events.performed_by as user_id,
                COUNT(*) as total_actions,
                SUM(attendance_events.action = 'present') as present_count,
                SUM(attendance_events.action = 'absent') as absent_count,
                MAX(attendance_events.performed_at) as last_activity
            ")
            ->get()
            ->keyBy('user_id');

        // Corrections = a 'present' event where an earlier 'absent' event
        // exists for the SAME assignment (the only correction path the
        // state machine allows) — its own query rather than a correlated
        // subquery nested inside the grouped query above, which would
        // ambiguously reference a non-aggregated column per engine.
        $correctionStats = AttendanceEvent::query()
            ->from('attendance_events as ae')
            ->join('duty_sessions', 'duty_sessions.id', '=', 'ae.duty_session_id')
            ->where('ae.action', 'present')
            ->whereExists(function ($q) {
                // id ordering, not performed_at, distinguishes "earlier" here
                // — two events in the same request can share a
                // second-precision timestamp, but insert (and therefore id)
                // order is always the true chronology within one assignment.
                $q->selectRaw('1')->from('attendance_events as prior')
                    ->whereColumn('prior.duty_assignment_id', 'ae.duty_assignment_id')
                    ->where('prior.action', 'absent')
                    ->whereColumn('prior.id', '<', 'ae.id');
            })
            ->whereDate('duty_sessions.date', '>=', $from)
            ->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('ae.performed_by')
            ->selectRaw('ae.performed_by as user_id, COUNT(*) as corrections_count')
            ->get()
            ->keyBy('user_id');

        $extraStats = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)
            ->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('extra_presents.marked_by')
            ->selectRaw('extra_presents.marked_by as user_id, COUNT(*) as extra_count, MAX(extra_presents.marked_at) as last_extra_activity')
            ->get()
            ->keyBy('user_id');

        $operators = User::whereIn('role', ['admin', 'operator'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($attendanceStats, $correctionStats, $extraStats) {
                $a = $attendanceStats->get($user->id);
                $c = $correctionStats->get($user->id);
                $e = $extraStats->get($user->id);

                $lastActivity = collect([$a?->last_activity, $e?->last_extra_activity])->filter()->max();

                return [
                    'user' => $user,
                    'total_actions' => (int) ($a->total_actions ?? 0),
                    'present_count' => (int) ($a->present_count ?? 0),
                    'absent_count' => (int) ($a->absent_count ?? 0),
                    'corrections_count' => (int) ($c->corrections_count ?? 0),
                    'extra_count' => (int) ($e->extra_count ?? 0),
                    'last_activity' => $lastActivity,
                ];
            })
            ->filter(fn ($row) => $row['total_actions'] > 0 || $row['extra_count'] > 0)
            ->sortByDesc('total_actions')
            ->values();

        return ['from' => $from, 'to' => $to, 'operators' => $operators];
    }

    public function departmentBreakdown(?string $from = null, ?string $to = null, ?int $sessionId = null)
    {
        $query = DutyAssignment::query()
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id');

        if ($sessionId) {
            $query->where('duty_assignments.duty_session_id', $sessionId);
        } elseif ($from && $to) {
            $query->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
                ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to);
        }

        return $query->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->selectRaw("
                departments.id as department_id,
                departments.name as department_name,
                COUNT(*) as scheduled,
                SUM(duty_assignments.current_status = 'present') as present,
                SUM(duty_assignments.current_status = 'absent') as absent,
                SUM(duty_assignments.current_status = 'pending') as pending,
                ".$this->genderSelectRaw('duty_assignments.gender_snapshot').'
            ')
            ->get()
            ->map(function ($row) {
                $row->rate = $row->scheduled > 0 ? round(100 * $row->present / $row->scheduled, 1) : 0;
                $row->genderBreakdown = $this->genderBreakdownFromRow($row);

                return $row;
            });
    }

    /**
     * Conditional-sum SQL fragment bucketing every row into Male/Female/Unknown
     * (see App\Support\Gender) for scheduled + each of present/absent/pending.
     * Shared by every report method so the bucketing logic exists in exactly
     * one place.
     */
    private function genderSelectRaw(string $genderColumn, string $statusColumn = 'current_status'): string
    {
        $g = Gender::caseSql($genderColumn);

        return "
            SUM({$g} = 'Male') as male_scheduled,
            SUM({$g} = 'Female') as female_scheduled,
            SUM({$g} = 'Unknown') as unknown_scheduled,
            SUM({$g} = 'Male' AND {$statusColumn} = 'present') as male_present,
            SUM({$g} = 'Female' AND {$statusColumn} = 'present') as female_present,
            SUM({$g} = 'Unknown' AND {$statusColumn} = 'present') as unknown_present,
            SUM({$g} = 'Male' AND {$statusColumn} = 'absent') as male_absent,
            SUM({$g} = 'Female' AND {$statusColumn} = 'absent') as female_absent,
            SUM({$g} = 'Unknown' AND {$statusColumn} = 'absent') as unknown_absent,
            SUM({$g} = 'Male' AND {$statusColumn} = 'pending') as male_pending,
            SUM({$g} = 'Female' AND {$statusColumn} = 'pending') as female_pending,
            SUM({$g} = 'Unknown' AND {$statusColumn} = 'pending') as unknown_pending
        ";
    }

    /**
     * @return array{scheduled: array, present: array, absent: array, pending: array}
     */
    private function genderBreakdownFromRow($row): array
    {
        $pick = fn (string $status) => [
            'male' => (int) ($row->{"male_{$status}"} ?? 0),
            'female' => (int) ($row->{"female_{$status}"} ?? 0),
            'unknown' => (int) ($row->{"unknown_{$status}"} ?? 0),
        ];

        return [
            'scheduled' => $pick('scheduled'),
            'present' => $pick('present'),
            'absent' => $pick('absent'),
            'pending' => $pick('pending'),
        ];
    }

    /**
     * Sanitize a user/data-derived string into a safe filename component —
     * no path traversal, no unsafe characters.
     */
    public function safeFilenamePart(string $value): string
    {
        $slug = Str::slug($value, '-');

        return $slug !== '' ? $slug : 'report';
    }
}
