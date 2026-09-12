<?php

namespace App\Http\Controllers;

use App\Models\DutySession;
use App\Models\Event;
use App\Models\EventPlan;
use App\Models\Miqaat;
use App\Models\SessionReopenEvent;
use App\Models\Venue;
use App\Services\AttendanceService;
use App\Services\EventPlanningService;
use App\Services\ForecastingService;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DutySessionController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ReportService $reports,
        private readonly ForecastingService $forecasting,
        private readonly EventPlanningService $planning,
    ) {}

    public function index(): View
    {
        $sessions = DutySession::withCount([
            'dutyAssignments as scheduled_count',
            'dutyAssignments as present_count' => fn ($q) => $q->where('current_status', 'present'),
        ])->with('venue')->latest('date')->latest('id')->paginate(15);

        return view('sessions.index', ['sessions' => $sessions]);
    }

    /**
     * Create Event Session (Phase 2): a classic GET-and-resubmit wizard —
     * each select auto-submits the form (existing `onchange="this.form.
     * submit()"` convention, e.g. attendance/list.blade.php's department
     * filter), reloading this same action with the choices made so far as
     * query params. No fetch/AJAX/SPA — every reveal is a normal page
     * load. The final "Create Session" submit is the separate POST below.
     */
    public function create(Request $request): View
    {
        $miqaatId = $request->query('miqaat_id');
        $eventId = $request->query('event_id');

        $miqaats = Miqaat::where('active', true)->orderBy('sort_order')->orderBy('name')->get();

        $events = $miqaatId
            ? Event::where('miqaat_id', $miqaatId)->where('active', true)->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        $venues = Venue::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
        $venueId = $request->query('venue_id');

        $forecast = null;
        if ($eventId) {
            $event = Event::find($eventId);
            $venue = $venueId ? Venue::find($venueId) : null;
            $forecast = $event ? $this->forecasting->forecast($event, $venue) : null;
        }

        return view('sessions.create', [
            'miqaats' => $miqaats,
            'events' => $events,
            'venues' => $venues,
            'selectedMiqaatId' => $miqaatId,
            'selectedEventId' => $eventId,
            'selectedVenueId' => $venueId,
            'selectedDate' => $request->query('date', now()->toIst()->format('Y-m-d')),
            'forecast' => $forecast,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'miqaat_id' => [
                'required', 'integer',
                Rule::exists('miqaats', 'id')->where('active', true),
            ],
            'event_id' => [
                'required', 'integer',
                Rule::exists('events', 'id')->where('active', true)->where('miqaat_id', $request->input('miqaat_id')),
            ],
            'venue_id' => [
                'required', 'integer',
                Rule::exists('venues', 'id')->where('active', true),
            ],
            'date' => ['required', 'date'],
            'h_year' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            // Phase 4: optional link back to the plan this session was
            // created from, so the plan can be marked finalized. Session
            // creation itself is unchanged — this never bypasses or
            // duplicates the duplicate-protection below.
            'event_plan_id' => ['nullable', 'integer', Rule::exists('event_plans', 'id')],
        ], [
            'event_id.exists' => 'Please choose an active Event that belongs to the selected Miqaat.',
        ]);

        $miqaat = Miqaat::findOrFail($validated['miqaat_id']);
        $event = Event::findOrFail($validated['event_id']);
        $venue = Venue::findOrFail($validated['venue_id']);

        $dutySession = DB::transaction(function () use ($validated, $miqaat, $event, $venue) {
            // Row-lock any existing, still-open session with this exact
            // structured identity so two concurrent submissions can't both
            // create one — a closed session with the same identity is a
            // legitimate re-run (correction, redo), not a duplicate, and
            // is deliberately not blocked.
            $duplicate = DutySession::where('miqaat_id', $miqaat->id)
                ->where('event_id', $event->id)
                ->where('venue_id', $venue->id)
                ->whereDate('date', $validated['date'])
                ->where('status', '!=', 'closed')
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            return DutySession::create([
                'name' => $event->name.' — '.$venue->name,
                'date' => $validated['date'],
                'h_year' => $validated['h_year'] ?? null,
                'miqaat' => $miqaat->name,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'draft',
                'miqaat_id' => $miqaat->id,
                'event_id' => $event->id,
                'venue_id' => $venue->id,
            ]);
        });

        if (! $dutySession->wasRecentlyCreated) {
            return back()->withInput()->withErrors([
                'event_id' => 'An open session for this exact Miqaat, Event, Venue and Date already exists — open it instead of creating a duplicate.',
            ]);
        }

        if (! empty($validated['event_plan_id'])) {
            EventPlan::where('id', $validated['event_plan_id'])->update([
                'duty_session_id' => $dutySession->id,
                'status' => 'finalized',
            ]);
        }

        return redirect()
            ->route('sessions.show', $dutySession)
            ->with('status', 'Event session created.');
    }

    public function show(DutySession $dutySession): View
    {
        $dutySession->load('importBatches.uploadedBy', 'reopenEvents.reopenedBy', 'miqaatRef', 'event', 'venue', 'eventPlan');

        $planVsActual = $dutySession->eventPlan
            ? $this->planning->planVsActualByDepartment($dutySession->eventPlan, $dutySession)
            : [];

        return view('sessions.show', ['dutySession' => $dutySession, 'planVsActual' => $planVsActual]);
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
            'genderBreakdown' => $this->reports->sessionGenderSummary($dutySession),
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

    public function reopen(Request $request, DutySession $dutySession): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', array_keys(SessionReopenEvent::REASONS))],
            'detail' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'],
        ]);

        $outcome = $this->attendance->reopenSession($dutySession, $request->user(), $validated['reason'], $validated['detail'] ?? null);

        return match ($outcome['result']) {
            'reopened' => redirect()->route('sessions.show', $dutySession)
                ->with('status', 'Session reopened for correction.'),
            'forbidden' => redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'You do not have permission to reopen a session.'),
            default => redirect()->route('sessions.show', $dutySession)
                ->with('status_error', 'Only a Closed session can be reopened.'),
        };
    }
}
