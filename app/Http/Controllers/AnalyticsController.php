<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
use App\Services\ReportService;
use App\Support\Gender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * All analytics are derived live from duty_assignments/extra_presents via
 * SQL aggregation — no summary tables, no application-memory recomputation.
 * current_status is the sole source of Present/Absent/Pending; source
 * Status/Scanned are never read here.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function overview(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);
        $departmentId = $request->query('department_id');
        $sessionId = $request->query('session_id');

        $base = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to);

        if ($departmentId) {
            $base->where('duty_assignments.department_id', $departmentId);
        }
        if ($sessionId) {
            $base->where('duty_assignments.duty_session_id', $sessionId);
        }

        $genderCase = Gender::caseSql('duty_assignments.gender_snapshot');
        $totals = (clone $base)->selectRaw("
            COUNT(*) as scheduled,
            SUM(current_status = 'present') as present,
            SUM(current_status = 'absent') as absent,
            SUM(current_status = 'pending') as pending,
            SUM({$genderCase} = 'Male') as male_scheduled,
            SUM({$genderCase} = 'Female') as female_scheduled,
            SUM({$genderCase} = 'Unknown') as unknown_scheduled,
            SUM({$genderCase} = 'Male' AND current_status = 'present') as male_present,
            SUM({$genderCase} = 'Female' AND current_status = 'present') as female_present,
            SUM({$genderCase} = 'Unknown' AND current_status = 'present') as unknown_present
        ")->first();

        $genderBreakdown = [
            'scheduled' => ['male' => (int) $totals->male_scheduled, 'female' => (int) $totals->female_scheduled, 'unknown' => (int) $totals->unknown_scheduled],
            'present' => ['male' => (int) $totals->male_present, 'female' => (int) $totals->female_present, 'unknown' => (int) $totals->unknown_present],
        ];

        $extraQuery = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to);
        if ($departmentId) {
            $extraQuery->where('extra_presents.department_id', $departmentId);
        }
        if ($sessionId) {
            $extraQuery->where('extra_presents.duty_session_id', $sessionId);
        }
        $extra = $extraQuery->count();

        $scheduled = (int) ($totals->scheduled ?? 0);
        $present = (int) ($totals->present ?? 0);
        $absent = (int) ($totals->absent ?? 0);
        $pending = (int) ($totals->pending ?? 0);
        $rate = $scheduled > 0 ? round(100 * $present / $scheduled, 1) : null;

        // Session-level trend points — each point is one real Duty Session,
        // never combined with another even if same date, per spec.
        $trend = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.id', 'duty_sessions.name', 'duty_sessions.date', 'duty_sessions.status')
            ->orderBy('duty_sessions.date')
            ->selectRaw("
                duty_sessions.id as session_id,
                duty_sessions.name as session_name,
                duty_sessions.date as session_date,
                duty_sessions.status as session_status,
                COUNT(*) as scheduled,
                SUM(duty_assignments.current_status = 'present') as present
            ")
            ->limit(60)
            ->get()
            ->map(function ($row) {
                $row->rate = $row->scheduled > 0 ? round(100 * $row->present / $row->scheduled, 1) : 0;

                return $row;
            });

        // Compact top-departments list for the Overview tab.
        $departments = $this->departmentBreakdown($from, $to, $sessionId)->take(5);

        return view('analytics.overview', [
            'from' => $from,
            'to' => $to,
            'departmentId' => $departmentId,
            'sessionId' => $sessionId,
            'sessionOptions' => DutySession::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->get(['id', 'name', 'date']),
            'departmentOptions' => Department::orderBy('name')->get(['id', 'name']),
            'scheduled' => $scheduled,
            'genderBreakdown' => $genderBreakdown,
            'present' => $present,
            'absent' => $absent,
            'pending' => $pending,
            'extra' => $extra,
            'rate' => $rate,
            'trend' => $trend,
            'departments' => $departments,
        ]);
    }

    public function departments(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);
        $sessionId = $request->query('session_id');

        $departments = $this->departmentBreakdown($from, $to, $sessionId);

        return view('analytics.departments', [
            'from' => $from,
            'to' => $to,
            'sessionId' => $sessionId,
            'departments' => $departments,
        ]);
    }

    public function insights(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);

        $departments = $this->departmentBreakdown($from, $to, null);
        $qualifying = $departments->filter(fn ($d) => $d->scheduled >= 5);

        $totalScheduled = $departments->sum('scheduled');
        $totalExtra = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->count();

        $mostActiveSession = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.id', 'duty_sessions.name', 'duty_sessions.date')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->selectRaw('duty_sessions.id, duty_sessions.name, duty_sessions.date, COUNT(*) as scheduled')
            ->first();

        $multiAssignmentPeople = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_assignments.khidmatguzar_id')
            ->havingRaw('COUNT(*) > 1')
            ->get(['duty_assignments.khidmatguzar_id'])
            ->count();

        $pendingInActive = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->where('duty_sessions.status', 'active')
            ->where('duty_assignments.current_status', 'pending')
            ->count();

        return view('analytics.insights', [
            'from' => $from,
            'to' => $to,
            'totalScheduled' => $totalScheduled,
            'totalExtra' => $totalExtra,
            'bestDepartment' => $qualifying->sortByDesc('rate')->first(),
            'worstDepartment' => $qualifying->sortBy('rate')->first(),
            'mostActiveSession' => $mostActiveSession,
            'multiAssignmentPeople' => $multiAssignmentPeople,
            'pendingInActive' => $pendingInActive,
        ]);
    }

    /**
     * Operator Analytics (Phase 6): operational workload visibility, NOT a
     * leaderboard — no ranking, no gamification, just what each
     * admin/operator account actually did in the selected window. The query
     * itself lives in ReportService::operatorActivityReport() so this
     * on-screen view and the Phase 8 PDF/Excel export can never disagree.
     * "View Activity" links into the Audit Log rather than duplicating a
     * second activity feed.
     */
    public function operators(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);

        return view('analytics.operators', $this->reports->operatorActivityReport($from, $to));
    }

    /**
     * Khidmatguzar Directory — PERSON-level, one row per Khidmatguzar,
     * paginated. Per-row stats are the same all-time definitions used by
     * the Profile page (Directory and Profile must agree, per spec), using
     * scalar correlated subqueries rather than a joined GROUP BY — a join
     * across both duty_assignments and extra_presents would fan-out and
     * silently inflate the counts, which this avoids entirely.
     *
     * Filters (department/jamaat/date range/has-served) narrow WHICH
     * people appear via WHERE EXISTS — they do not change what a shown
     * person's own all-time numbers mean.
     */
    /**
     * Drill-down entry point: Overview/Departments status counts and
     * department rows link here with department_id/status/session_id (and
     * from/to) preset, narrowing to exactly the people whose OWN assignment
     * satisfies every given condition together — not independently, which
     * would otherwise let a person surface under "Dept X / Absent" via an
     * absence in a completely different department.
     */
    public function directory(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $departmentId = $request->query('department_id');
        $status = $request->query('status');
        $sessionId = $request->query('session_id');
        $jamaat = trim((string) $request->query('jamaat', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $hasServed = $request->boolean('has_served');

        $khidmatguzars = Khidmatguzar::query()
            ->selectRaw("
                khidmatguzars.*,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id) as total_duties,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id AND da.current_status = 'present') as present_count,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id AND da.current_status = 'absent') as absent_count,
                (SELECT COUNT(DISTINCT da.duty_session_id) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id) as sessions_served,
                (SELECT COUNT(*) FROM extra_presents ep WHERE ep.khidmatguzar_id = khidmatguzars.id) as extra_count,
                (SELECT MAX(ds.date) FROM duty_assignments da2 JOIN duty_sessions ds ON ds.id = da2.duty_session_id WHERE da2.khidmatguzar_id = khidmatguzars.id) as last_duty_date
            ")
            ->when($query !== '' && mb_strlen($query) >= 2, function ($q) use ($query) {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
                $q->where(fn ($qq) => $qq->where('its_id', 'like', "%{$query}%")->orWhere('full_name', 'like', "%{$escaped}%"));
            })
            ->when($jamaat !== '', fn ($q) => $q->where('jamaat', 'like', "%{$jamaat}%"))
            ->when($departmentId || $status || $sessionId || ($from && $to), function ($q) use ($departmentId, $status, $sessionId, $from, $to) {
                $q->whereExists(function ($sub) use ($departmentId, $status, $sessionId, $from, $to) {
                    $sub->selectRaw('1')->from('duty_assignments')
                        ->whereColumn('duty_assignments.khidmatguzar_id', 'khidmatguzars.id')
                        ->when($departmentId, fn ($qq) => $qq->where('duty_assignments.department_id', $departmentId))
                        ->when($status, fn ($qq) => $qq->where('duty_assignments.current_status', $status))
                        ->when($sessionId, fn ($qq) => $qq->where('duty_assignments.duty_session_id', $sessionId))
                        ->when($from && $to && ! $sessionId, function ($qq) use ($from, $to) {
                            $qq->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
                                ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to);
                        });
                });
            })
            ->when($hasServed, fn ($q) => $q->whereExists(function ($sub) {
                $sub->selectRaw('1')->from('duty_assignments')
                    ->whereColumn('duty_assignments.khidmatguzar_id', 'khidmatguzars.id');
            }))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        $khidmatguzars->getCollection()->transform(function ($k) {
            $k->rate = $k->total_duties > 0 ? round(100 * $k->present_count / $k->total_duties, 1) : null;

            return $k;
        });

        return view('analytics.profile-search', [
            'query' => $query,
            'departmentId' => $departmentId,
            'status' => $status,
            'sessionId' => $sessionId,
            'jamaat' => $jamaat,
            'from' => $from,
            'to' => $to,
            'hasServed' => $hasServed,
            'departmentOptions' => Department::orderBy('name')->get(['id', 'name']),
            'departmentName' => $departmentId ? Department::find($departmentId)?->name : null,
            'sessionName' => $sessionId ? DutySession::find($sessionId)?->name : null,
            'matches' => $khidmatguzars,
        ]);
    }

    /**
     * Deepest drill-down level: one specific DutyAssignment's full
     * AttendanceEvent trail — who marked what, when, and via what context
     * (individual/bulk/offline sync). Read-only, same broad visibility as
     * every other analytics screen; never edits history.
     */
    public function assignmentDetail(DutyAssignment $dutyAssignment): View
    {
        $dutyAssignment->load(['khidmatguzar:id,its_id,full_name', 'department:id,name', 'dutySession:id,name,date,status']);

        $events = AttendanceEvent::where('duty_assignment_id', $dutyAssignment->id)
            ->with('performedBy:id,name')
            ->orderBy('performed_at')
            ->get();

        return view('analytics.assignment', [
            'assignment' => $dutyAssignment,
            'events' => $events,
        ]);
    }

    public function profile(Request $request, Khidmatguzar $khidmatguzar): View
    {
        $stats = DutyAssignment::where('khidmatguzar_id', $khidmatguzar->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(current_status = 'present') as present,
                SUM(current_status = 'absent') as absent,
                SUM(current_status = 'pending') as pending,
                COUNT(DISTINCT duty_session_id) as sessions_served,
                COUNT(DISTINCT department_id) as departments_served
            ")->first();

        $total = (int) ($stats->total ?? 0);
        $present = (int) ($stats->present ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $pending = (int) ($stats->pending ?? 0);
        $sessionsServed = (int) ($stats->sessions_served ?? 0);
        $departmentsServed = (int) ($stats->departments_served ?? 0);
        $rate = $total > 0 ? round(100 * $present / $total, 1) : null;

        $firstLast = DutyAssignment::where('duty_assignments.khidmatguzar_id', $khidmatguzar->id)
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->selectRaw('MIN(duty_sessions.date) as first_duty, MAX(duty_sessions.date) as last_duty')
            ->first();

        $departmentBreakdown = DutyAssignment::where('duty_assignments.khidmatguzar_id', $khidmatguzar->id)
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->selectRaw("
                departments.id as department_id,
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

        $historyDeptId = $request->query('history_department_id');
        $historyStatus = $request->query('history_status');
        $historyFrom = $request->query('history_from');
        $historyTo = $request->query('history_to');

        $recentHistory = DutyAssignment::where('khidmatguzar_id', $khidmatguzar->id)
            ->with(['dutySession:id,name,date,status', 'department:id,name'])
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->when($historyDeptId, fn ($q) => $q->where('duty_assignments.department_id', $historyDeptId))
            ->when($historyStatus, fn ($q) => $q->where('duty_assignments.current_status', $historyStatus))
            ->when($historyFrom && $historyTo, fn ($q) => $q->whereDate('duty_sessions.date', '>=', $historyFrom)->whereDate('duty_sessions.date', '<=', $historyTo))
            ->orderByDesc('duty_sessions.date')
            ->orderByDesc('duty_assignments.id')
            ->select('duty_assignments.*')
            ->paginate(15, ['*'], 'history')
            ->withQueryString();

        $extraHistory = ExtraPresent::where('khidmatguzar_id', $khidmatguzar->id)
            ->with(['dutySession:id,name,date'])
            ->orderByDesc('marked_at')
            ->paginate(15, ['*'], 'extra')
            ->withQueryString();

        return view('analytics.profile', [
            'khidmatguzar' => $khidmatguzar,
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'pending' => $pending,
            'sessionsServed' => $sessionsServed,
            'departmentsServed' => $departmentsServed,
            'firstDuty' => $firstLast?->first_duty,
            'lastDuty' => $firstLast?->last_duty,
            'rate' => $rate,
            'departmentBreakdown' => $departmentBreakdown,
            'recentHistory' => $recentHistory,
            'extraHistory' => $extraHistory,
            'extraTotal' => ExtraPresent::where('khidmatguzar_id', $khidmatguzar->id)->count(),
            'historyDeptId' => $historyDeptId,
            'historyStatus' => $historyStatus,
            'historyFrom' => $historyFrom,
            'historyTo' => $historyTo,
            'historyDepartmentOptions' => $departmentBreakdown->pluck('department_name', 'department_id'),
        ]);
    }

    private function departmentBreakdown(string $from, string $to, ?string $sessionId)
    {
        $query = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->join('departments', 'departments.id', '=', 'duty_assignments.department_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to);

        if ($sessionId) {
            $query->where('duty_assignments.duty_session_id', $sessionId);
        }

        return $query->groupBy('departments.id', 'departments.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
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
     * @return array{0: string, 1: string}
     */
    private function resolveRange(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (! $from || ! $to) {
            $to = now()->toIst()->format('Y-m-d');
            $from = now()->toIst()->subDays(30)->format('Y-m-d');
        }

        return [$from, $to];
    }
}
