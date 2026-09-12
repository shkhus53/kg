<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Miqaat;
use App\Services\MasterDataAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $audit) {}

    public function index(): View
    {
        return view('admin.masters.events.index', [
            'events' => Event::with('miqaat')->orderBy('sort_order')->orderBy('name')->get(),
            'miqaats' => Miqaat::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $normalized = Event::normalize($validated['name']);

        if (Event::where('miqaat_id', $validated['miqaat_id'])->where('normalized_key', $normalized)->exists()) {
            return back()->withErrors(['name' => 'This event already exists under the selected Miqaat.'])->withInput();
        }

        $event = Event::create([...$validated, 'normalized_key' => $normalized]);

        $this->audit->logLifecycle('event', $event->id, 'created', $request->user());

        return redirect()->route('masters.events.index')->with('status', 'Event created.');
    }

    public function edit(Event $event): View
    {
        return view('admin.masters.events.edit', [
            'event' => $event,
            'miqaats' => Miqaat::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $validated = $this->validated($request);
        $normalized = Event::normalize($validated['name']);

        if (Event::where('miqaat_id', $validated['miqaat_id'])->where('normalized_key', $normalized)->whereKeyNot($event->id)->exists()) {
            return back()->withErrors(['name' => 'This event already exists under the selected Miqaat.'])->withInput();
        }

        $before = $event->only(['miqaat_id', 'name', 'code', 'family', 'description']);

        $event->update([...$validated, 'normalized_key' => $normalized]);

        $this->audit->logUpdate('event', $event->id, $before, $validated, $request->user());

        return redirect()->route('masters.events.index')->with('status', 'Event updated.');
    }

    public function toggleActive(Request $request, Event $event): RedirectResponse
    {
        $event->update(['active' => ! $event->active]);

        $this->audit->logLifecycle('event', $event->id, $event->active ? 'activated' : 'deactivated', $request->user());

        return back()->with('status', $event->active ? 'Event activated.' : 'Event deactivated.');
    }

    /**
     * @return array{miqaat_id:int,name:string,code:?string,family:?string,description:?string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'miqaat_id' => ['required', 'integer', 'exists:miqaats,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'family' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
