<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPlan;
use App\Models\Miqaat;
use App\Models\Venue;
use App\Services\EventPlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 4: Intelligent Event Planning. Miqaat -> Event -> Venue -> Date
 * mirrors the Phase 2/3 Create Event Session wizard exactly — the same
 * structured identity, the same ForecastingService call — so a plan is
 * never a second event/session identity system, just an earlier planning
 * step in front of it.
 */
class EventPlanController extends Controller
{
    public function __construct(private readonly EventPlanningService $planning) {}

    public function index(): View
    {
        $plans = EventPlan::with(['miqaat', 'event', 'venue', 'dutySession'])
            ->latest('planned_date')->latest('id')->paginate(15);

        return view('planning.index', ['plans' => $plans]);
    }

    public function create(Request $request): View
    {
        $miqaatId = $request->query('miqaat_id');
        $eventId = $request->query('event_id');
        $venueId = $request->query('venue_id');

        $miqaats = Miqaat::where('active', true)->orderBy('sort_order')->orderBy('name')->get();

        $events = $miqaatId
            ? Event::where('miqaat_id', $miqaatId)->where('active', true)->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        $venues = Venue::where('active', true)->orderBy('sort_order')->orderBy('name')->get();

        $forecast = null;
        if ($eventId) {
            $event = Event::find($eventId);
            $venue = $venueId ? Venue::find($venueId) : null;
            $forecast = $event ? $this->planning->previewForecast($event, $venue) : null;
        }

        return view('planning.create', [
            'miqaats' => $miqaats,
            'events' => $events,
            'venues' => $venues,
            'selectedMiqaatId' => $miqaatId,
            'selectedEventId' => $eventId,
            'selectedVenueId' => $venueId,
            'selectedDate' => $request->query('date', now()->toIst()->addDay()->format('Y-m-d')),
            'forecast' => $forecast,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'miqaat_id' => ['required', 'integer', Rule::exists('miqaats', 'id')->where('active', true)],
            'event_id' => [
                'required', 'integer',
                Rule::exists('events', 'id')->where('active', true)->where('miqaat_id', $request->input('miqaat_id')),
            ],
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('active', true)],
            'planned_date' => ['required', 'date'],
            'h_year' => ['nullable', 'string', 'max:50'],
        ]);

        $event = Event::findOrFail($validated['event_id']);
        $venue = Venue::findOrFail($validated['venue_id']);

        $forecast = $this->planning->previewForecast($event, $venue);
        $departments = $this->planning->buildDepartmentPlan($forecast);

        $plan = EventPlan::create([
            'miqaat_id' => $validated['miqaat_id'],
            'event_id' => $validated['event_id'],
            'venue_id' => $validated['venue_id'],
            'planned_date' => $validated['planned_date'],
            'h_year' => $validated['h_year'] ?? null,
            'status' => 'draft',
            'forecast_snapshot' => $forecast,
            'departments' => $departments,
            'recommended_total' => $forecast['recommended_scheduled_hr'] ?? 0,
            'planned_total' => $this->planning->plannedTotal($departments),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('planning.show', $plan)->with('status', 'Plan created from the current forecast.');
    }

    public function show(EventPlan $eventPlan): View
    {
        $eventPlan->load(['miqaat', 'event', 'venue', 'dutySession']);

        $departments = $this->planning->withPlanningStatus($eventPlan->departments ?? []);
        usort($departments, fn ($a, $b) => $b['recommended'] <=> $a['recommended']);

        return view('planning.show', [
            'plan' => $eventPlan,
            'departments' => $departments,
            'overallStatus' => $eventPlan->planningStatus(),
        ]);
    }

    public function update(Request $request, EventPlan $eventPlan): RedirectResponse
    {
        if ($eventPlan->isFinalized()) {
            return back()->with('flash_error', 'This plan is finalized — it is linked to a duty session and can no longer be adjusted.');
        }

        $validated = $request->validate([
            'planned' => ['required', 'array'],
            'planned.*' => ['required', 'integer', 'min:0'],
        ]);

        $plannedByDeptId = array_map('intval', $validated['planned']);
        $departments = $this->planning->applyPlannedQuantities($eventPlan->departments ?? [], $plannedByDeptId);

        $eventPlan->update([
            'departments' => $departments,
            'planned_total' => $this->planning->plannedTotal($departments),
        ]);

        return redirect()->route('planning.show', $eventPlan)->with('status', 'Plan updated.');
    }
}
