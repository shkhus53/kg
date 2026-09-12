<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Miqaat;
use App\Services\MasterDataAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MiqaatController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $audit) {}

    public function index(): View
    {
        return view('admin.masters.miqaats.index', [
            'miqaats' => Miqaat::withCount('events')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        if (Miqaat::where('normalized_key', Miqaat::normalize($validated['name']))->exists()) {
            return back()->withErrors(['name' => 'A Miqaat with this name already exists.'])->withInput();
        }

        $miqaat = Miqaat::create([
            ...$validated,
            'normalized_key' => Miqaat::normalize($validated['name']),
        ]);

        $this->audit->logLifecycle('miqaat', $miqaat->id, 'created', $request->user());

        return redirect()->route('masters.miqaats.index')->with('status', 'Miqaat created.');
    }

    public function edit(Miqaat $miqaat): View
    {
        return view('admin.masters.miqaats.edit', ['miqaat' => $miqaat]);
    }

    public function update(Request $request, Miqaat $miqaat): RedirectResponse
    {
        $validated = $this->validated($request);

        if (Miqaat::where('normalized_key', Miqaat::normalize($validated['name']))->whereKeyNot($miqaat->id)->exists()) {
            return back()->withErrors(['name' => 'A Miqaat with this name already exists.'])->withInput();
        }

        $before = $miqaat->only(['name', 'code', 'description']);

        $miqaat->update([
            ...$validated,
            'normalized_key' => Miqaat::normalize($validated['name']),
        ]);

        $this->audit->logUpdate('miqaat', $miqaat->id, $before, $validated, $request->user());

        return redirect()->route('masters.miqaats.index')->with('status', 'Miqaat updated.');
    }

    public function toggleActive(Request $request, Miqaat $miqaat): RedirectResponse
    {
        $miqaat->update(['active' => ! $miqaat->active]);

        $this->audit->logLifecycle('miqaat', $miqaat->id, $miqaat->active ? 'activated' : 'deactivated', $request->user());

        return back()->with('status', $miqaat->active ? 'Miqaat activated.' : 'Miqaat deactivated.');
    }

    /**
     * @return array{name:string,code:?string,description:?string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
