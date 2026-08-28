<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
use App\Support\Gender;
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
        $stats = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->selectRaw("
                COUNT(*) as scheduled,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                ".$this->genderSelectRaw('gender_snapshot')."
            ")->first();

        $scheduled = (int) ($stats->scheduled ?? 0);
        $present = (int) ($stats->present ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $pending = (int) ($stats->pending ?? 0);
        $genderBreakdown = $this->genderBreakdownFromRow($stats);

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
            'scheduled' => $scheduled,
            'present' => $present,
            'absent' => $absent,
            'pending' => $pending,
            'extraCount' => $extraPresents->count(),
            'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null,
            'genderBreakdown' => $genderBreakdown,
            'departments' => $this->departmentBreakdown(sessionId: $dutySession->id),
            'assignments' => $assignments,
            'extraPresents' => $extraPresents,
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
            $query->whereHas('dutySession', fn ($q) => $q->whereBetween('date', [$from, $to]));
            $extraQuery->whereHas('dutySession', fn ($q) => $q->whereBetween('date', [$from, $to]));
        }

        $stats = (clone $query)->selectRaw("
            COUNT(*) as scheduled,
            SUM(current_status = 'present') as present,
            SUM(current_status = 'absent') as absent,
            SUM(current_status = 'pending') as pending,
            ".$this->genderSelectRaw('gender_snapshot')."
        ")->first();

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
                ".$this->genderSelectRaw('gender_snapshot')."
            ")->first();

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

        $sections = $departments->map(function (Department $department) use ($from, $to, $sessionId) {
            $assignmentQuery = DutyAssignment::where('department_id', $department->id);
            $extraQuery = ExtraPresent::where('department_id', $department->id);

            if ($sessionId) {
                $assignmentQuery->where('duty_session_id', $sessionId);
                $extraQuery->where('duty_session_id', $sessionId);
            } else {
                $assignmentQuery->whereHas('dutySession', fn ($q) => $q->whereBetween('date', [$from, $to]));
                $extraQuery->whereHas('dutySession', fn ($q) => $q->whereBetween('date', [$from, $to]));
            }

            $stats = (clone $assignmentQuery)->selectRaw("
                COUNT(*) as scheduled,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                ".$this->genderSelectRaw('gender_snapshot')."
            ")->first();

            $scheduled = (int) ($stats->scheduled ?? 0);
            $present = (int) ($stats->present ?? 0);
            $absent = (int) ($stats->absent ?? 0);
            $pending = (int) ($stats->pending ?? 0);

            $assignments = (clone $assignmentQuery)
                ->with(['khidmatguzar:id,its_id,full_name', 'dutySession:id,name,date', 'attendanceMarkedBy:id,name'])
                ->orderBy('duty_session_id')
                ->orderBy('id')
                ->get();

            $extraPresents = (clone $extraQuery)
                ->with(['khidmatguzar:id,its_id,full_name', 'markedBy:id,name'])
                ->orderBy('marked_at')
                ->get();

            return [
                'department' => $department,
                'scheduled' => $scheduled,
                'present' => $present,
                'absent' => $absent,
                'pending' => $pending,
                'extraCount' => $extraPresents->count(),
                'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null,
                'genderBreakdown' => $this->genderBreakdownFromRow($stats),
                'assignments' => $assignments,
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

    private function departmentBreakdown(?string $from = null, ?string $to = null, ?int $sessionId = null)
    {
        $query = DutyAssignment::query()
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id');

        if ($sessionId) {
            $query->where('duty_assignments.duty_session_id', $sessionId);
        } elseif ($from && $to) {
            $query->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
                ->whereBetween('duty_sessions.date', [$from, $to]);
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
                ".$this->genderSelectRaw('duty_assignments.gender_snapshot')."
            ")
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
