<?php

namespace App\Http\Controllers;

use App\Models\DutySession;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DutySessionController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(): View
    {
        $sessions = DutySession::latest('date')->latest('id')->paginate(15);

        return view('sessions.index', ['sessions' => $sessions]);
    }

    public function create(): View
    {
        return view('sessions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'h_year' => ['nullable', 'string', 'max:50'],
            'miqaat' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $dutySession = DutySession::create([
            ...$validated,
            'status' => 'draft',
        ]);

        return redirect()
            ->route('sessions.show', $dutySession)
            ->with('status', 'Duty session created.');
    }

    public function show(DutySession $dutySession): View
    {
        $dutySession->load('importBatches.uploadedBy');

        return view('sessions.show', ['dutySession' => $dutySession]);
    }

    public function activate(DutySession $dutySession): RedirectResponse
    {
        if ($dutySession->status !== 'draft') {
            return redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'Only a Draft session can be activated.');
        }

        $dutySession->update(['status' => 'active']);

        return redirect()->route('sessions.show', $dutySession)
            ->with('status', 'Session activated. Attendance marking is now open.');
    }

    public function closeSummary(DutySession $dutySession): View
    {
        $scheduled = $dutySession->dutyAssignments()->count();
        $present = $dutySession->dutyAssignments()->where('current_status', 'present')->count();
        $absent = $dutySession->dutyAssignments()->where('current_status', 'absent')->count();
        $pending = $dutySession->dutyAssignments()->where('current_status', 'pending')->count();
        $extra = $dutySession->extraPresents()->count();

        return view('sessions.close', [
            'dutySession' => $dutySession,
            'scheduled' => $scheduled,
            'present' => $present,
            'absent' => $absent,
            'pending' => $pending,
            'extra' => $extra,
            'rate' => $scheduled > 0 ? round(100 * $present / $scheduled, 1) : 0,
        ]);
    }

    public function close(Request $request, DutySession $dutySession): RedirectResponse
    {
        $outcome = $this->attendance->closeSession($dutySession, $request->user());

        return match ($outcome['result']) {
            'closed' => redirect()->route('sessions.show', $dutySession)
                ->with('status', 'Session closed and locked. Attendance records are now read-only.'),
            'pending_remain' => redirect()->route('attendance.shell.pending', $dutySession)
                ->with('flash_error', $outcome['pending_count'].' pending assignment(s) remain — resolve them before closing.'),
            default => redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'Only an Active session can be closed.'),
        };
    }
}
