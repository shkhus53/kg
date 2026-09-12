<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\MasterDataAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VenueController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $audit) {}

    public function index(): View
    {
        return view('admin.masters.venues.index', [
            'venues' => Venue::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $normalized = Venue::normalize($validated['name']);

        if (Venue::where('normalized_key', $normalized)->exists()) {
            return back()->withErrors(['name' => 'A venue with this name already exists.'])->withInput();
        }

        $venue = Venue::create([...$validated, 'normalized_key' => $normalized]);

        $this->audit->logLifecycle('venue', $venue->id, 'created', $request->user());

        return redirect()->route('masters.venues.index')->with('status', 'Venue created.');
    }

    public function edit(Venue $venue): View
    {
        return view('admin.masters.venues.edit', ['venue' => $venue]);
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $validated = $this->validated($request);
        $normalized = Venue::normalize($validated['name']);

        if (Venue::where('normalized_key', $normalized)->whereKeyNot($venue->id)->exists()) {
            return back()->withErrors(['name' => 'A venue with this name already exists.'])->withInput();
        }

        $before = $venue->only(['name', 'city', 'area', 'address', 'latitude', 'longitude']);

        $venue->update([...$validated, 'normalized_key' => $normalized]);

        $this->audit->logUpdate('venue', $venue->id, $before, $validated, $request->user());

        return redirect()->route('masters.venues.index')->with('status', 'Venue updated.');
    }

    public function toggleActive(Request $request, Venue $venue): RedirectResponse
    {
        $venue->update(['active' => ! $venue->active]);

        $this->audit->logLifecycle('venue', $venue->id, $venue->active ? 'activated' : 'deactivated', $request->user());

        return back()->with('status', $venue->active ? 'Venue activated.' : 'Venue deactivated.');
    }

    /**
     * @return array{name:string,city:?string,area:?string,address:?string,latitude:?float,longitude:?float}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }
}
