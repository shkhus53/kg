<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\DutySession;
use App\Models\ExtraPresent;
use App\Models\Khidmatguzar;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function live(Request $request, DutySession $dutySession): View
    {
        $counts = $this->counts($dutySession);

        $itsId = trim((string) $request->query('its', ''));
        $nameQuery = trim((string) $request->query('name', ''));

        $matches = collect();
        $knownPerson = null;
        $alreadyExtra = null;
        $nameMatches = null;
        $departments = collect();

        if ($nameQuery !== '' && mb_strlen($nameQuery) >= 2) {
            $nameMatches = Khidmatguzar::where('full_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $nameQuery).'%')
                ->with(['dutyAssignments' => fn ($q) => $q->where('duty_session_id', $dutySession->id)->with('department')])
                ->orderBy('full_name')
                ->limit(20)
                ->get();
        } elseif ($itsId !== '') {
            $matches = DutyAssignment::where('duty_session_id', $dutySession->id)
                ->whereHas('khidmatguzar', fn ($q) => $q->where('its_id', $itsId))
                ->with(['khidmatguzar', 'department'])
                ->get();

            if ($matches->isEmpty()) {
                $knownPerson = Khidmatguzar::where('its_id', $itsId)->first();

                if ($knownPerson) {
                    $alreadyExtra = ExtraPresent::where('duty_session_id', $dutySession->id)
                        ->where('khidmatguzar_id', $knownPerson->id)
                        ->first();
                }

                $departments = $this->sessionDepartments($dutySession);
            }
        }

        return view('attendance.live', [
            'dutySession' => $dutySession,
            'counts' => $counts,
            'itsId' => $itsId,
            'nameQuery' => $nameQuery,
            'nameMatches' => $nameMatches,
            'matches' => $matches,
            'knownPerson' => $knownPerson,
            'alreadyExtra' => $alreadyExtra,
            'departments' => $departments,
        ]);
    }

    public function present(Request $request, DutySession $dutySession): RedirectResponse
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer'],
            'its' => ['nullable', 'string'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        if (count($validated['assignment_ids']) === 1) {
            $outcome = $this->attendance->markPresent($dutySession, (int) $validated['assignment_ids'][0], $request->user(), $validated['remark'] ?? null);
            $status = match ($outcome['result']) {
                'marked' => ['type' => 'success', 'message' => 'Marked Present.'],
                'already_present' => ['type' => 'info', 'message' => 'Already Present.'],
                'already_absent' => ['type' => 'warning', 'message' => 'Already marked Absent — cannot mark Present.'],
                'session_not_active' => ['type' => 'error', 'message' => 'Session is not active — attendance is read-only.'],
                default => ['type' => 'error', 'message' => 'Assignment not found.'],
            };
        } else {
            $outcome = $this->attendance->markPresentMany($dutySession, array_map('intval', $validated['assignment_ids']), $request->user(), $validated['remark'] ?? null);

            if (! empty($outcome['session_not_active'])) {
                $status = ['type' => 'error', 'message' => 'Session is not active — attendance is read-only.'];
            } else {
                $status = ['type' => 'success', 'message' => count($outcome['marked']).' marked Present.'
                    .(count($outcome['already_present']) + count($outcome['already_absent']) > 0 ? ' '.(count($outcome['already_present']) + count($outcome['already_absent'])).' skipped (already actioned by someone else).' : ''),
                ];
            }
        }

        return $this->redirectAfterMutation($request, $dutySession, $status);
    }

    public function absent(Request $request, DutySession $dutySession): RedirectResponse
    {
        $validated = $request->validate([
            'assignment_id' => ['required', 'integer'],
            'its' => ['nullable', 'string'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $outcome = $this->attendance->markAbsent($dutySession, (int) $validated['assignment_id'], $request->user(), $validated['remark'] ?? null);

        $status = match ($outcome['result']) {
            'marked' => ['type' => 'success', 'message' => 'Marked Absent.'],
            'already_present' => ['type' => 'info', 'message' => 'Already Present — cannot mark Absent here.'],
            'already_absent' => ['type' => 'info', 'message' => 'Already marked Absent.'],
            'session_not_active' => ['type' => 'error', 'message' => 'Session is not active — attendance is read-only.'],
            default => ['type' => 'error', 'message' => 'Assignment not found.'],
        };

        return $this->redirectAfterMutation($request, $dutySession, $status);
    }

    public function extraPresent(Request $request, DutySession $dutySession): RedirectResponse
    {
        $validated = $request->validate([
            'its' => ['required', 'string', 'max:20'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $department = Department::findOrFail($validated['department_id']);
        $khidmatguzar = Khidmatguzar::where('its_id', $validated['its'])->first();

        if ($khidmatguzar) {
            $outcome = $this->attendance->markExtraPresentKnown($dutySession, $khidmatguzar, $department, $request->user(), $validated['remark'] ?? null);
        } else {
            if (empty($validated['full_name'])) {
                return redirect()->route('attendance.shell.live', [$dutySession, 'its' => $validated['its']])
                    ->with('flash_error', 'Full Name is required to create a new Khidmatguzar.');
            }

            $outcome = $this->attendance->markExtraPresentNew($dutySession, $validated['its'], $validated['full_name'], $department, $request->user(), $validated['remark'] ?? null);
        }

        $status = match ($outcome['result']) {
            'marked' => ['type' => 'success', 'message' => 'Marked Extra Present.'],
            'already_extra' => ['type' => 'info', 'message' => 'Already marked Extra Present.'],
            'now_scheduled' => ['type' => 'warning', 'message' => 'This person now has a scheduled assignment in this session — search again.'],
            'invalid_department' => ['type' => 'error', 'message' => 'That department is not part of this session.'],
            'session_not_active' => ['type' => 'error', 'message' => 'Session is not active — attendance is read-only.'],
            default => ['type' => 'error', 'message' => 'Could not record Extra Present.'],
        };

        return redirect()->route('attendance.shell.live', [$dutySession, 'its' => $validated['its']])
            ->with('flash_'.$status['type'], $status['message']);
    }

    public function pending(Request $request, DutySession $dutySession): View
    {
        $search = trim((string) $request->query('q', ''));

        $query = DutyAssignment::where('duty_session_id', $dutySession->id)
            ->where('current_status', 'pending')
            ->with(['khidmatguzar', 'department']);

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('full_name_snapshot', 'like', "%{$search}%")
                ->orWhereHas('khidmatguzar', fn ($qq) => $qq->where('its_id', 'like', "%{$search}%")));
        }

        return view('attendance.pending', [
            'dutySession' => $dutySession,
            'search' => $search,
            'pendingAssignments' => $query->orderBy('id')->get(),
            'counts' => $this->counts($dutySession),
        ]);
    }

    public function absentAll(Request $request, DutySession $dutySession): RedirectResponse
    {
        $outcome = $this->attendance->markAllRemainingAbsent($dutySession, $request->user());

        $status = match ($outcome['result']) {
            'marked' => ['type' => 'success', 'message' => $outcome['count'].' pending assignment(s) marked Absent.'],
            'nothing_pending' => ['type' => 'info', 'message' => 'Nothing pending — already resolved.'],
            'session_not_active' => ['type' => 'error', 'message' => 'Session is not active — attendance is read-only.'],
            default => ['type' => 'error', 'message' => 'Could not process bulk Absent.'],
        };

        return redirect()->route('attendance.shell.pending', $dutySession)
            ->with('flash_'.$status['type'], $status['message']);
    }

    public function list(Request $request, DutySession $dutySession): View
    {
        $tab = $request->query('tab', 'all');
        $search = trim((string) $request->query('q', ''));
        $departmentId = $request->query('department_id');

        $counts = $this->counts($dutySession);

        if ($tab === 'extra') {
            $query = ExtraPresent::where('duty_session_id', $dutySession->id)->with(['khidmatguzar', 'department']);

            if ($search !== '') {
                $query->where(fn ($q) => $q->where('full_name_snapshot', 'like', "%{$search}%")->orWhere('its_id_snapshot', 'like', "%{$search}%"));
            }
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            $extraPresents = $query->orderByDesc('marked_at')->get();
            $assignments = collect();
        } else {
            $query = DutyAssignment::where('duty_session_id', $dutySession->id)->with(['khidmatguzar', 'department']);

            if (in_array($tab, ['present', 'pending'], true)) {
                $query->where('current_status', $tab);
            }
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('full_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('khidmatguzar', fn ($qq) => $qq->where('its_id', 'like', "%{$search}%")));
            }
            if ($departmentId) {
                $query->where('department_id', $departmentId);
            }

            $assignments = $query->orderBy('id')->get();
            $extraPresents = collect();
        }

        return view('attendance.list', [
            'dutySession' => $dutySession,
            'tab' => $tab,
            'search' => $search,
            'departmentId' => $departmentId,
            'departments' => $this->sessionDepartments($dutySession),
            'assignments' => $assignments,
            'extraPresents' => $extraPresents,
            'counts' => $counts,
        ]);
    }

    private function counts(DutySession $dutySession): array
    {
        $scheduled = $dutySession->dutyAssignments()->count();

        return [
            'scheduled' => $scheduled,
            'all' => $scheduled,
            'present' => $dutySession->dutyAssignments()->where('current_status', 'present')->count(),
            'pending' => $dutySession->dutyAssignments()->where('current_status', 'pending')->count(),
            'absent' => $dutySession->dutyAssignments()->where('current_status', 'absent')->count(),
            'extra' => $dutySession->extraPresents()->count(),
        ];
    }

    private function sessionDepartments(DutySession $dutySession)
    {
        return Department::whereIn('id', $dutySession->dutyAssignments()->distinct()->pluck('department_id'))->orderBy('name')->get();
    }

    private function redirectAfterMutation(Request $request, DutySession $dutySession, array $status): RedirectResponse
    {
        if ($request->input('return_to') === 'pending') {
            return redirect()->route('attendance.shell.pending', $dutySession)
                ->with('flash_'.$status['type'], $status['message']);
        }

        return redirect()->route('attendance.shell.live', [$dutySession, 'its' => $request->input('its')])
            ->with('flash_'.$status['type'], $status['message']);
    }
}
