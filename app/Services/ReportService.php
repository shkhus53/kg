<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
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
                SUM(current_status = 'pending') as pending
            ")->first();

        $scheduled = (int) ($stats->scheduled ?? 0);
        $present = (int) ($stats->present ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $pending = (int) ($stats->pending ?? 0);

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
            'departments' => $this->departmentBreakdown(sessionId: $dutySession->id),
            'assignments' => $assignments,
            'extraPresents' => $extraPresents,
        ];
    }

    /**
     * Report 2: Department report, scoped by date range and/or a specific
     * session.
     */
    public function departmentReport(string $from, string $to, ?int $sessionId): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'sessionId' => $sessionId,
            'session' => $sessionId ? DutySession::find($sessionId) : null,
            'departments' => $this->departmentBreakdown($from, $to, $sessionId),
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
                SUM(current_status = 'pending') as pending
            ")->first();

        $total = (int) ($stats->total ?? 0);
        $present = (int) ($stats->present ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $pending = (int) ($stats->pending ?? 0);

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
            'departmentBreakdown' => $departmentBreakdown,
            'history' => $history,
            'extraHistory' => $extraHistory,
        ];
    }

    private function departmentBreakdown(?string $from = null, ?string $to = null, ?int $sessionId = null)
    {
        $query = DutyAssignment::query()
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id');

        if ($from && $to) {
            $query->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
                ->whereBetween('duty_sessions.date', [$from, $to]);
        }

        if ($sessionId) {
            $query->where('duty_assignments.duty_session_id', $sessionId);
        }

        return $query->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->selectRaw("
                departments.id as department_id,
                departments.name as department_name,
                COUNT(*) as scheduled,
                SUM(duty_assignments.current_status = 'present') as present,
                SUM(duty_assignments.current_status = 'absent') as absent,
                SUM(duty_assignments.current_status = 'pending') as pending
            ")
            ->get()
            ->map(function ($row) {
                $row->rate = $row->scheduled > 0 ? round(100 * $row->present / $row->scheduled, 1) : 0;

                return $row;
            });
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
