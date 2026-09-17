<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceDetailReportExport;
use App\Exports\DepartmentDetailReportExport;
use App\Exports\DepartmentReportExport;
use App\Exports\KhidmatguzarReportExport;
use App\Exports\ManagementSummaryReportExport;
use App\Exports\OperatorActivityReportExport;
use App\Exports\SessionAttendanceExport;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\Khidmatguzar;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use App\Support\Gender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Every route is read-only (GET only) and reachable by every authenticated
 * role including Viewer — report generation never mutates attendance data —
 * except the Report Builder's bulk Present/Absent actions below, which are
 * POST and separately gated behind `can:mark_attendance`.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AttendanceService $attendance,
    ) {}

    public function index(): View
    {
        return view('reports.index', [
            'sessions' => DutySession::orderByDesc('date')->orderByDesc('id')->get(['id', 'name', 'date', 'status']),
        ]);
    }

    public function sessionPreview(DutySession $dutySession): View
    {
        return view('reports.session', $this->reports->sessionReport($dutySession));
    }

    public function sessionPdf(DutySession $dutySession): Response
    {
        $data = $this->reports->sessionReport($dutySession);
        $pdf = Pdf::loadView('reports.pdf.session', $data)->setPaper('a4', 'portrait');

        $filename = 'session-attendance-'.$this->reports->safeFilenamePart($dutySession->name.'-'.$dutySession->date->format('Y-m-d')).'.pdf';

        return $pdf->download($filename);
    }

    public function sessionExcel(DutySession $dutySession): BinaryFileResponse
    {
        $data = $this->reports->sessionReport($dutySession);
        $filename = 'session-attendance-'.$this->reports->safeFilenamePart($dutySession->name.'-'.$dutySession->date->format('Y-m-d')).'.xlsx';

        return Excel::download(new SessionAttendanceExport($data), $filename);
    }

    public function departmentPreview(Request $request): View
    {
        [$from, $to, $sessionId, $departmentId] = $this->resolveDepartmentScope($request);

        $extra = ['sessions' => DutySession::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->get(['id', 'name', 'date'])];
        $extra['departments'] = Department::orderBy('name')->get(['id', 'name']);
        $extra['departmentId'] = $departmentId;

        if ($departmentId) {
            return view('reports.department-detail', $this->reports->departmentDetailReport([$departmentId], $from, $to, $sessionId) + $extra);
        }

        return view('reports.department', $this->reports->departmentReport($from, $to, $sessionId) + $extra);
    }

    public function departmentPdf(Request $request): Response
    {
        [$from, $to, $sessionId, $departmentId] = $this->resolveDepartmentScope($request);

        if ($departmentId) {
            $data = $this->reports->departmentDetailReport([$departmentId], $from, $to, $sessionId);
            $pdf = Pdf::loadView('reports.pdf.department-detail', $data)->setPaper('a4', 'portrait');
            $deptName = $data['sections']->first()['department']->name ?? 'department';
            $filename = 'department-attendance-'.$this->reports->safeFilenamePart($deptName.'-'.$from.'-to-'.$to).'.pdf';

            return $pdf->download($filename);
        }

        $data = $this->reports->departmentReport($from, $to, $sessionId);
        $pdf = Pdf::loadView('reports.pdf.department', $data)->setPaper('a4', 'portrait');

        $filename = 'department-attendance-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.pdf';

        return $pdf->download($filename);
    }

    public function departmentExcel(Request $request): BinaryFileResponse
    {
        [$from, $to, $sessionId, $departmentId] = $this->resolveDepartmentScope($request);

        if ($departmentId) {
            $data = $this->reports->departmentDetailReport([$departmentId], $from, $to, $sessionId);
            $deptName = $data['sections']->first()['department']->name ?? 'department';
            $filename = 'department-attendance-'.$this->reports->safeFilenamePart($deptName.'-'.$from.'-to-'.$to).'.xlsx';

            return Excel::download(new DepartmentDetailReportExport($data), $filename);
        }

        $data = $this->reports->departmentReport($from, $to, $sessionId);
        $filename = 'department-attendance-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.xlsx';

        return Excel::download(new DepartmentReportExport($data), $filename);
    }

    public function khidmatguzarPreview(Khidmatguzar $khidmatguzar): View
    {
        return view('reports.khidmatguzar', $this->reports->khidmatguzarReport($khidmatguzar));
    }

    public function khidmatguzarPdf(Khidmatguzar $khidmatguzar): Response
    {
        $data = $this->reports->khidmatguzarReport($khidmatguzar);
        $pdf = Pdf::loadView('reports.pdf.khidmatguzar', $data)->setPaper('a4', 'portrait');

        $filename = 'khidmatguzar-'.$this->reports->safeFilenamePart($khidmatguzar->its_id).'-attendance.pdf';

        return $pdf->download($filename);
    }

    public function khidmatguzarExcel(Khidmatguzar $khidmatguzar): BinaryFileResponse
    {
        $data = $this->reports->khidmatguzarReport($khidmatguzar);
        $filename = 'khidmatguzar-'.$this->reports->safeFilenamePart($khidmatguzar->its_id).'-attendance.xlsx';

        return Excel::download(new KhidmatguzarReportExport($data), $filename);
    }

    /**
     * Operator Activity report (Phase 8): built from ReportService::
     * operatorActivityReport(), the same query the on-screen Operator
     * Analytics page uses, so this export can never disagree with it. Same
     * sensitivity as Operator Analytics itself (staff-activity visibility)
     * — gated by the view_audit_log permission, not the general reports
     * auth-only access every other report has.
     */
    public function operatorActivityPdf(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->reports->operatorActivityReport($from, $to);
        $pdf = Pdf::loadView('reports.pdf.operator-activity', $data)->setPaper('a4', 'landscape');

        $filename = 'operator-activity-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.pdf';

        return $pdf->download($filename);
    }

    public function operatorActivityExcel(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->reports->operatorActivityReport($from, $to);
        $filename = 'operator-activity-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.xlsx';

        return Excel::download(new OperatorActivityReportExport($data), $filename);
    }

    /**
     * Phase 7 Report Builder — Attendance Detail. Deliberately the only
     * fully-implemented report type here (per the "don't build a giant
     * generic BI system" instruction): Department Performance, Operator
     * Activity, Planning vs Actual, and Import Quality are already each a
     * dedicated, tested page (Analytics Departments/Operators/Planning,
     * Import Center) — the builder links into those with the same filter
     * values carried over rather than recalculating them a second way.
     */
    public function builder(Request $request): View
    {
        $filters = $this->resolveBuilderFilters($request);

        $results = $this->reports->attendanceDetailQuery($filters)->paginate(25)->withQueryString();
        $totals = $this->reports->attendanceDetailTotals($filters);

        return view('reports.builder', [
            'filters' => $filters,
            'results' => $results,
            'totals' => $totals,
            'sessionOptions' => DutySession::orderByDesc('date')->get(['id', 'name', 'date']),
            'departmentOptions' => Department::orderBy('name')->get(['id', 'name']),
            'operatorOptions' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name']),
            'genderOptions' => [Gender::MALE, Gender::FEMALE, Gender::UNKNOWN],
        ]);
    }

    /**
     * Bulk-mark selected Report Builder rows Present. Rows are grouped by
     * duty session (a filtered page can span several) since attendance
     * mutation is always scoped to one session at a time.
     */
    public function builderMarkPresent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer'],
        ]);

        $assignmentIds = array_map('intval', $validated['assignment_ids']);

        $assignments = DutyAssignment::whereIn('id', $assignmentIds)->get(['id', 'duty_session_id', 'current_status']);

        // Absent -> Present is a permission-gated correction (see
        // AttendanceController::present) — checked across the WHOLE
        // selection before any group is touched, same all-or-nothing rule.
        if (! $request->user()->hasPermission('correct_attendance')
            && $assignments->contains('current_status', 'absent')) {
            abort(403, 'Correcting Absent to Present requires the Attendance Correction permission.');
        }

        $marked = 0;
        $corrected = 0;
        $skippedClosed = 0;

        foreach ($assignments->groupBy('duty_session_id') as $sessionId => $group) {
            $session = DutySession::find($sessionId);

            $outcome = $this->attendance->markPresentMany($session, $group->pluck('id')->all(), $request->user());

            if (! empty($outcome['session_not_active'])) {
                $skippedClosed += $group->count();

                continue;
            }

            $marked += count($outcome['marked']);
            $corrected += count($outcome['corrected']);
        }

        return redirect()->route('reports.builder', $request->query())
            ->with('flash_success', ($marked + $corrected).' marked Present.'
                .($skippedClosed > 0 ? ' '.$skippedClosed.' skipped (session not active).' : ''));
    }

    /**
     * Bulk-mark selected Report Builder rows Absent. Present -> Absent is
     * never allowed (see AttendanceService::markAbsent) so those rows are
     * silently skipped rather than blocking the whole request.
     */
    public function builderMarkAbsent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer'],
        ]);

        $assignmentIds = array_map('intval', $validated['assignment_ids']);

        $assignments = DutyAssignment::whereIn('id', $assignmentIds)->get(['id', 'duty_session_id']);

        $marked = 0;
        $skippedPresent = 0;
        $skippedClosed = 0;

        foreach ($assignments->groupBy('duty_session_id') as $sessionId => $group) {
            $session = DutySession::find($sessionId);

            $outcome = $this->attendance->markAbsentMany($session, $group->pluck('id')->all(), $request->user());

            if (! empty($outcome['session_not_active'])) {
                $skippedClosed += $group->count();

                continue;
            }

            $marked += count($outcome['marked']);
            $skippedPresent += count($outcome['skipped_present']);
        }

        return redirect()->route('reports.builder', $request->query())
            ->with('flash_success', $marked.' marked Absent.'
                .($skippedPresent > 0 ? ' '.$skippedPresent.' skipped (already Present).' : '')
                .($skippedClosed > 0 ? ' '.$skippedClosed.' skipped (session not active).' : ''));
    }

    public function builderPdf(Request $request): Response
    {
        $filters = $this->resolveBuilderFilters($request);
        $rows = $this->reports->attendanceDetailQuery($filters)->get();
        $totals = $this->reports->attendanceDetailTotals($filters);

        $pdf = Pdf::loadView('reports.pdf.attendance-detail', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters])->setPaper('a4', 'landscape');

        return $pdf->download('attendance-detail-'.$this->reports->safeFilenamePart(($filters['from'] ?? 'all').'-to-'.($filters['to'] ?? 'all')).'.pdf');
    }

    public function builderExcel(Request $request): BinaryFileResponse
    {
        $filters = $this->resolveBuilderFilters($request);
        $rows = $this->reports->attendanceDetailQuery($filters)->get();
        $totals = $this->reports->attendanceDetailTotals($filters);

        $filename = 'attendance-detail-'.$this->reports->safeFilenamePart(($filters['from'] ?? 'all').'-to-'.($filters['to'] ?? 'all')).'.xlsx';

        return Excel::download(new AttendanceDetailReportExport($rows, $totals, $filters), $filename);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveBuilderFilters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'session_id' => $request->query('session_id'),
            'department_id' => $request->query('department_id'),
            'operator_id' => $request->query('operator_id'),
            'status' => $request->query('status'),
            'gender' => $request->query('gender'),
        ]);
    }

    public function managementSummaryPdf(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->reports->managementSummary($from, $to);
        $pdf = Pdf::loadView('reports.pdf.management-summary', $data)->setPaper('a4', 'portrait');

        $filename = 'management-summary-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.pdf';

        return $pdf->download($filename);
    }

    public function managementSummaryExcel(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->reports->managementSummary($from, $to);
        $filename = 'management-summary-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.xlsx';

        return Excel::download(new ManagementSummaryReportExport($data), $filename);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        return [
            $request->query('from') ?: now()->toIst()->subDays(30)->format('Y-m-d'),
            $request->query('to') ?: now()->toIst()->format('Y-m-d'),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?int, 3: ?int}
     */
    private function resolveDepartmentScope(Request $request): array
    {
        $sessionId = $request->query('session_id') ? (int) $request->query('session_id') : null;

        if ($sessionId) {
            DutySession::findOrFail($sessionId);
        }

        $departmentId = $request->query('department_id') ? (int) $request->query('department_id') : null;

        if ($departmentId) {
            Department::findOrFail($departmentId);
        }

        $from = $request->query('from') ?: now()->toIst()->subDays(30)->format('Y-m-d');
        $to = $request->query('to') ?: now()->toIst()->format('Y-m-d');

        return [$from, $to, $sessionId, $departmentId];
    }
}
