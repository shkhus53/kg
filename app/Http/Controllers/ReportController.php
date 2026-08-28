<?php

namespace App\Http\Controllers;

use App\Exports\DepartmentReportExport;
use App\Exports\KhidmatguzarReportExport;
use App\Exports\SessionAttendanceExport;
use App\Models\DutySession;
use App\Models\Khidmatguzar;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * All routes are read-only (GET only) and reachable by every authenticated
 * role including Viewer — report generation never mutates attendance data.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

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

    public function sessionExcel(DutySession $dutySession): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $this->reports->sessionReport($dutySession);
        $filename = 'session-attendance-'.$this->reports->safeFilenamePart($dutySession->name.'-'.$dutySession->date->format('Y-m-d')).'.xlsx';

        return Excel::download(new SessionAttendanceExport($data), $filename);
    }

    public function departmentPreview(Request $request): View
    {
        [$from, $to, $sessionId] = $this->resolveDepartmentScope($request);

        return view('reports.department', $this->reports->departmentReport($from, $to, $sessionId) + [
            'sessions' => DutySession::whereBetween('date', [$from, $to])->orderByDesc('date')->get(['id', 'name', 'date']),
        ]);
    }

    public function departmentPdf(Request $request): Response
    {
        [$from, $to, $sessionId] = $this->resolveDepartmentScope($request);
        $data = $this->reports->departmentReport($from, $to, $sessionId);
        $pdf = Pdf::loadView('reports.pdf.department', $data)->setPaper('a4', 'portrait');

        $filename = 'department-attendance-'.$this->reports->safeFilenamePart($from.'-to-'.$to).'.pdf';

        return $pdf->download($filename);
    }

    public function departmentExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        [$from, $to, $sessionId] = $this->resolveDepartmentScope($request);
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

    public function khidmatguzarExcel(Khidmatguzar $khidmatguzar): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $this->reports->khidmatguzarReport($khidmatguzar);
        $filename = 'khidmatguzar-'.$this->reports->safeFilenamePart($khidmatguzar->its_id).'-attendance.xlsx';

        return Excel::download(new KhidmatguzarReportExport($data), $filename);
    }

    /**
     * @return array{0: string, 1: string, 2: ?int}
     */
    private function resolveDepartmentScope(Request $request): array
    {
        $sessionId = $request->query('session_id') ? (int) $request->query('session_id') : null;

        if ($sessionId) {
            DutySession::findOrFail($sessionId);
        }

        $from = $request->query('from') ?: now()->subDays(30)->format('Y-m-d');
        $to = $request->query('to') ?: now()->format('Y-m-d');

        return [$from, $to, $sessionId];
    }
}
