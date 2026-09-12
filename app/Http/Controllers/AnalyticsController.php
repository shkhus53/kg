<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\EventPlan;
use App\Models\ExtraPresent;
use App\Models\ImportBatch;
use App\Models\Khidmatguzar;
use App\Models\SessionReopenEvent;
use App\Services\EventPlanningService;
use App\Services\OperationalAlertService;
use App\Services\ReportService;
use App\Support\Gender;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /**
     * Rows in {@link planningAnalysis()} with `planned == 0 && actual > 0`,
     * or `actual` exceeding `planned` by more than this fraction, are
     * flagged underplanned. 20% is a deliberately simple, documented
     * threshold — not a statistical model.
     */
    private const UNDERPLANNED_GAP_THRESHOLD = 0.2;

    /** ImportBatch invalid_rows/total_rows at or above this ratio is an exception, not a statistical anomaly model. */
    private const HIGH_INVALID_IMPORT_RATIO = 0.2;

    /** A department needs at least this many observed sessions in range before a pattern (not a one-off) is claimed. */
    private const MIN_SESSIONS_FOR_PATTERN = 3;

    /** A trend needs at least this many distinct dates with data before it is drawn — fewer than this is an incident list, not a trend. */
    private const MIN_DATES_FOR_TREND = 5;

    public function __construct(
        private readonly ReportService $reports,
        private readonly EventPlanningService $planning,
        private readonly OperationalAlertService $alertsService,
    ) {}

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

        [$utcStart, $utcEnd] = $this->operationalBoundsUtc($from, $to);

        // Corrections: a 'present' AttendanceEvent with an earlier 'absent'
        // event for the SAME assignment — the only correction path the
        // state machine allows (see ReportService::operatorActivityReport()
        // for the identical per-operator version of this same rule).
        $corrections = AttendanceEvent::query()
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

        $reopenedSessions = SessionReopenEvent::whereBetween('reopened_at', [$utcStart, $utcEnd])
            ->distinct('duty_session_id')->count('duty_session_id');

        $importsCount = ImportBatch::whereBetween('created_at', [$utcStart, $utcEnd])->count();

        $operatorsActive = AttendanceEvent::whereBetween('performed_at', [$utcStart, $utcEnd])
            ->distinct('performed_by')->count('performed_by');

        return view('analytics.overview', [
            'corrections' => $corrections,
            'reopenedSessions' => $reopenedSessions,
            'importsCount' => $importsCount,
            'operatorsActive' => $operatorsActive,
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
        $gender = $request->query('gender', 'all');
        $genderBucket = in_array($gender, [Gender::MALE, Gender::FEMALE, Gender::UNKNOWN], true) ? $gender : null;

        // Every filter EXCEPT gender, so gender counts below reflect the
        // current search/department/session/etc. scope but are not
        // themselves narrowed by the gender filter being applied to the
        // list — this is what lets "Total = Male + Female + Unknown" hold
        // for whatever the other filters currently select.
        $baseQuery = Khidmatguzar::query()
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
            }));

        // One aggregate query (GROUP BY the same Gender bucketing used
        // everywhere else in the app) instead of loading rows into PHP —
        // scoped to every active filter except gender itself.
        $genderCounts = ['Male' => 0, 'Female' => 0, 'Unknown' => 0];
        $genderRows = (clone $baseQuery)
            ->selectRaw(Gender::caseSql('khidmatguzars.gender').' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');
        foreach ($genderRows as $bucket => $total) {
            $genderCounts[$bucket] = (int) $total;
        }
        $totalCount = array_sum($genderCounts);

        $khidmatguzars = (clone $baseQuery)
            ->selectRaw("
                khidmatguzars.*,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id) as total_duties,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id AND da.current_status = 'present') as present_count,
                (SELECT COUNT(*) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id AND da.current_status = 'absent') as absent_count,
                (SELECT COUNT(DISTINCT da.duty_session_id) FROM duty_assignments da WHERE da.khidmatguzar_id = khidmatguzars.id) as sessions_served,
                (SELECT COUNT(*) FROM extra_presents ep WHERE ep.khidmatguzar_id = khidmatguzars.id) as extra_count,
                (SELECT MAX(ds.date) FROM duty_assignments da2 JOIN duty_sessions ds ON ds.id = da2.duty_session_id WHERE da2.khidmatguzar_id = khidmatguzars.id) as last_duty_date
            ")
            ->when($genderBucket, fn ($q) => $q->whereRaw(Gender::caseSql('khidmatguzars.gender').' = ?', [$genderBucket]))
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
            'gender' => $gender,
            'genderCounts' => $genderCounts,
            'totalCount' => $totalCount,
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

    /**
     * The operator picks a From/To date in APP_OPERATIONAL_TIMEZONE terms
     * ("the whole operational day"), but every timestamp column (as
     * opposed to a DATE-only column like duty_sessions.date) is stored in
     * UTC. Converting the boundary once, here, is what keeps every
     * timestamp filter in this controller correct without re-deriving the
     * Phase 9/9.1 timezone bug per callsite: midnight IST on $from is NOT
     * midnight UTC, so a naive whereDate() on a timestamp column would
     * silently clip or include the wrong rows near the day boundary.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function operationalBoundsUtc(string $from, string $to): array
    {
        $tz = config('app.operational_timezone');

        return [
            Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc(),
            Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay()->utc(),
        ];
    }

    /**
     * Planning Performance (Phase 6): Forecast -> Plan -> Imported Duty
     * List, per finalized EventPlan in range. Draft plans are excluded —
     * they have no linked DutySession yet, so there is nothing "actual" to
     * compare against. Never recomputes the forecast or the plan snapshot;
     * `recommended`/`planned` come straight from EventPlan::$departments,
     * `actual` comes from EventPlanningService::planVsActualByDepartment()
     * (the same Phase 4/5 method sessions.show already uses) — one
     * calculation, never duplicated.
     *
     * Planning Accuracy (documented formula, not a statistical model):
     *   per department (planned > 0 only):
     *     accuracy = 100 * (1 - |actual - planned| / planned), floored at 0
     *   overall accuracy = accuracy values weighted by `planned`
     * Departments with planned = 0 are excluded from the accuracy average
     * — dividing by zero is undefined, not "0% accurate" — and are instead
     * surfaced separately as underplanned/missing-from-plan.
     *
     * @return array<string,mixed>
     */
    private function planningAnalysis(string $from, string $to): array
    {
        $plans = EventPlan::with(['event', 'venue', 'dutySession'])
            ->where('status', 'finalized')
            ->whereNotNull('duty_session_id')
            ->whereDate('planned_date', '>=', $from)
            ->whereDate('planned_date', '<=', $to)
            ->orderByDesc('planned_date')
            ->get();

        $departmentTotals = []; // keyed by department name: times flagged underplanned
        $departmentObserved = []; // keyed by department name: sessions observed at all
        $planRows = [];
        $sumRecommended = 0;
        $sumPlanned = 0;
        $sumActual = 0;
        $weightedAccuracySum = 0.0;
        $accuracyWeight = 0;

        foreach ($plans as $plan) {
            $actualRows = $this->planning->planVsActualByDepartment($plan, $plan->dutySession);
            $recommendedByName = collect($plan->departments ?? [])->keyBy('name');
            $extraByDept = ExtraPresent::where('duty_session_id', $plan->duty_session_id)
                ->selectRaw('department_id, COUNT(*) as extra_count')
                ->groupBy('department_id')->pluck('extra_count', 'department_id');

            $rows = [];
            foreach ($actualRows as $row) {
                $recommended = (int) ($recommendedByName->get($row['name'])['recommended'] ?? 0);
                $extra = (int) ($extraByDept[$row['department_id']] ?? 0);
                $underplanned = ($row['planned'] === 0 && $row['actual'] > 0)
                    || ($row['planned'] > 0 && $row['actual'] > $row['planned'] * (1 + self::UNDERPLANNED_GAP_THRESHOLD));

                $accuracy = null;
                if ($row['planned'] > 0) {
                    $accuracy = max(0.0, 100 * (1 - abs($row['actual'] - $row['planned']) / $row['planned']));
                    $weightedAccuracySum += $accuracy * $row['planned'];
                    $accuracyWeight += $row['planned'];
                }

                $rows[] = [...$row, 'recommended' => $recommended, 'extra' => $extra, 'underplanned' => $underplanned, 'accuracy' => $accuracy];

                $sumRecommended += $recommended;
                $sumPlanned += $row['planned'];
                $sumActual += $row['actual'];

                $departmentObserved[$row['name']] = ($departmentObserved[$row['name']] ?? 0) + 1;
                if ($underplanned) {
                    $departmentTotals[$row['name']] = ($departmentTotals[$row['name']] ?? 0) + 1;
                }
            }

            $planRows[] = ['plan' => $plan, 'rows' => $rows];
        }

        $repeatedUnderplanned = collect($departmentTotals)
            ->filter(fn ($count) => $count >= 2)
            ->map(fn ($count, $name) => ['name' => $name, 'sessions' => $count])
            ->sortByDesc('sessions')->values();

        // Phase 7: department pattern intelligence — require a minimum
        // number of OBSERVED sessions before calling anything a "pattern"
        // (one bad session is an incident, not a pattern). No black-box
        // score: the pattern label is a plain ratio of times-underplanned
        // to sessions-observed, shown alongside both raw numbers.
        $departmentPatterns = collect($departmentObserved)
            ->filter(fn ($observed) => $observed >= self::MIN_SESSIONS_FOR_PATTERN)
            ->map(function ($observed, $name) use ($departmentTotals) {
                $underplannedCount = $departmentTotals[$name] ?? 0;

                return [
                    'name' => $name,
                    'sessions_observed' => $observed,
                    'times_underplanned' => $underplannedCount,
                    'pattern' => $underplannedCount / $observed >= 0.5 ? 'persistently_underplanned' : 'stable',
                ];
            })
            ->sortByDesc('times_underplanned')->values();

        return [
            'plans' => $planRows,
            'sum_recommended' => $sumRecommended,
            'sum_planned' => $sumPlanned,
            'sum_actual' => $sumActual,
            'overall_gap' => $sumActual - $sumPlanned,
            'overall_accuracy' => $accuracyWeight > 0 ? round($weightedAccuracySum / $accuracyWeight, 1) : null,
            'repeated_underplanned' => $repeatedUnderplanned,
            'department_patterns' => $departmentPatterns,
        ];
    }

    public function planning(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);

        return view('analytics.planning', ['from' => $from, 'to' => $to] + $this->planningAnalysis($from, $to));
    }

    /**
     * Phase 7: Operational Alert Center — dynamically computed, never
     * persisted (see OperationalAlertService docblock for why). Every
     * alert is deterministic, links to an existing detail page, and is
     * grouped by severity for scanability.
     */
    public function alerts(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);
        [$utcStart, $utcEnd] = $this->operationalBoundsUtc($from, $to);

        $alerts = $this->alertsService->detect($from, $to, $utcStart, $utcEnd);

        return view('analytics.alerts', [
            'from' => $from,
            'to' => $to,
            'highAlerts' => collect($alerts)->where('severity', 'high')->values(),
            'mediumAlerts' => collect($alerts)->where('severity', 'medium')->values(),
        ]);
    }

    /**
     * Phase 7: Management Summary — a concise, factual, date-scoped
     * rollup. No "health score", no marketing language: every number here
     * is one of the same already-tested aggregations used elsewhere
     * (Overview, Planning, Exceptions/Alerts) and none are recomputed with
     * different logic.
     */
    public function summary(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);

        return view('analytics.summary', $this->reports->managementSummary($from, $to));
    }

    /**
     * Exceptions (Phase 6): deterministic, explainable conditions only — no
     * ML, no black-box scoring. Every row here traces to one plain-English
     * reason and one existing query; nothing is inferred statistically.
     */
    public function exceptions(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);
        [$utcStart, $utcEnd] = $this->operationalBoundsUtc($from, $to);

        $pendingInActive = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->where('duty_sessions.status', 'active')
            ->where('duty_assignments.current_status', 'pending')
            ->count();

        $planning = $this->planningAnalysis($from, $to);
        $underplannedRows = collect($planning['plans'])
            ->flatMap(fn ($p) => collect($p['rows'])->filter(fn ($r) => $r['underplanned'])
                ->map(fn ($r) => [...$r, 'plan' => $p['plan']]))
            ->values();

        $highInvalidImports = ImportBatch::whereBetween('created_at', [$utcStart, $utcEnd])
            ->where('total_rows', '>', 0)
            ->get()
            ->filter(fn ($b) => ($b->invalid_rows / $b->total_rows) >= self::HIGH_INVALID_IMPORT_RATIO)
            ->values();

        $repeatedReopens = SessionReopenEvent::whereBetween('reopened_at', [$utcStart, $utcEnd])
            ->groupBy('duty_session_id')
            ->havingRaw('COUNT(*) >= 2')
            ->with('dutySession:id,name,date')
            ->selectRaw('duty_session_id, COUNT(*) as reopen_count')
            ->get();

        // Unusually high Extra Present: >=5 in one session, or Extra
        // Present at 30%+ of that session's Present count — whichever
        // threshold is met, both documented, neither a statistical model.
        $extraBySession = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.id', 'duty_sessions.name', 'duty_sessions.date')
            ->selectRaw('duty_sessions.id as session_id, duty_sessions.name as session_name, duty_sessions.date as session_date, COUNT(*) as extra_count')
            ->get();

        $presentBySession = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->where('duty_assignments.current_status', 'present')
            ->groupBy('duty_sessions.id')
            ->selectRaw('duty_sessions.id as session_id, COUNT(*) as present_count')
            ->pluck('present_count', 'session_id');

        $highExtraPresentSessions = $extraBySession->filter(function ($row) use ($presentBySession) {
            $present = (int) ($presentBySession[$row->session_id] ?? 0);

            return $row->extra_count >= 5 || ($present > 0 && $row->extra_count / $present >= 0.3);
        })->values();

        return view('analytics.exceptions', [
            'from' => $from,
            'to' => $to,
            'pendingInActive' => $pendingInActive,
            'underplannedRows' => $underplannedRows,
            'highInvalidImports' => $highInvalidImports,
            'repeatedReopens' => $repeatedReopens,
            'highExtraPresentSessions' => $highExtraPresentSessions,
        ]);
    }

    /**
     * Phase 7: operational trends. Every metric here is a DAILY AGGREGATE
     * — e.g. "daily attendance rate" means SUM(Present) ÷ SUM(Scheduled)
     * for all sessions on that day, never the average of each session's
     * own percentage (averaging percentages would let one tiny session
     * distort a day dominated by a much bigger one). No definition here
     * differs from Overview/Planning/Summary — the underlying SQL grouping
     * is the only thing that changes (by date instead of by session).
     *
     * A series only renders once it has at least
     * self::MIN_DATES_FOR_TREND distinct dates with data in range —
     * below that it is an incident list, not a trend, and showing a chart
     * would imply a pattern the data does not support. Each of the four
     * series (attendance, Extra Present, planning, session volume) is
     * checked independently, since one can have enough history while
     * another (e.g. planning, which requires a finalized EventPlan) does
     * not yet.
     */
    public function trends(Request $request): View
    {
        [$from, $to] = $this->resolveRange($request);

        $attendanceByDate = DutyAssignment::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'duty_assignments.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.date')
            ->orderBy('duty_sessions.date')
            ->selectRaw("duty_sessions.date, COUNT(*) as scheduled, SUM(current_status = 'present') as present")
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'scheduled' => (int) $row->scheduled, 'present' => (int) $row->present, 'rate' => $row->scheduled > 0 ? round(100 * $row->present / $row->scheduled, 1) : 0]);

        $extraByDate = ExtraPresent::query()
            ->join('duty_sessions', 'duty_sessions.id', '=', 'extra_presents.duty_session_id')
            ->whereDate('duty_sessions.date', '>=', $from)->whereDate('duty_sessions.date', '<=', $to)
            ->groupBy('duty_sessions.date')
            ->orderBy('duty_sessions.date')
            ->selectRaw('duty_sessions.date, COUNT(*) as extra')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'extra' => (int) $row->extra]);

        $sessionVolumeByDate = DutySession::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->groupBy('date')->orderBy('date')
            ->selectRaw('date, COUNT(*) as sessions')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'sessions' => (int) $row->sessions]);

        $plans = EventPlan::with(['dutySession'])
            ->where('status', 'finalized')->whereNotNull('duty_session_id')
            ->whereDate('planned_date', '>=', $from)->whereDate('planned_date', '<=', $to)
            ->get();

        $planningByDate = [];
        foreach ($plans as $plan) {
            $date = $plan->planned_date->toDateString();
            $rows = $this->planning->planVsActualByDepartment($plan, $plan->dutySession);
            $planningByDate[$date]['planned'] = ($planningByDate[$date]['planned'] ?? 0) + array_sum(array_column($rows, 'planned'));
            $planningByDate[$date]['actual'] = ($planningByDate[$date]['actual'] ?? 0) + array_sum(array_column($rows, 'actual'));
        }
        $planningTrend = collect($planningByDate)->map(fn ($v, $date) => ['date' => $date, 'planned' => $v['planned'], 'actual' => $v['actual'], 'gap' => $v['actual'] - $v['planned']])
            ->sortBy('date')->values();

        return view('analytics.trends', [
            'from' => $from,
            'to' => $to,
            'minDates' => self::MIN_DATES_FOR_TREND,
            'attendanceByDate' => $attendanceByDate,
            'attendanceSufficient' => $attendanceByDate->count() >= self::MIN_DATES_FOR_TREND,
            'extraByDate' => $extraByDate,
            'extraSufficient' => $extraByDate->count() >= self::MIN_DATES_FOR_TREND,
            'sessionVolumeByDate' => $sessionVolumeByDate,
            'sessionVolumeSufficient' => $sessionVolumeByDate->count() >= self::MIN_DATES_FOR_TREND,
            'planningTrend' => $planningTrend,
            'planningSufficient' => $planningTrend->count() >= self::MIN_DATES_FOR_TREND,
        ]);
    }
}
