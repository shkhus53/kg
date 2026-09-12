<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\MasterDataAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $audit) {}

    public function index(): View
    {
        return view('admin.masters.departments.index', [
            'departments' => Department::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($this->duplicateExists(Department::class, $validated['name'])) {
            return back()->withErrors(['name' => 'A department with this name already exists.'])->withInput();
        }

        $department = Department::create([
            ...$validated,
            'normalized_key' => Department::normalize($validated['name']),
        ]);

        $this->audit->logLifecycle('department', $department->id, 'created', $request->user());

        return redirect()->route('masters.departments.index')->with('status', 'Department created.');
    }

    public function edit(Department $department): View
    {
        return view('admin.masters.departments.edit', ['department' => $department]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($this->duplicateExists(Department::class, $validated['name'], $department->id)) {
            return back()->withErrors(['name' => 'A department with this name already exists.'])->withInput();
        }

        $before = $department->only(['name', 'code', 'description']);

        $department->update([
            ...$validated,
            'normalized_key' => Department::normalize($validated['name']),
        ]);

        $this->audit->logUpdate('department', $department->id, $before, $validated, $request->user());

        return redirect()->route('masters.departments.index')->with('status', 'Department updated.');
    }

    public function toggleActive(Request $request, Department $department): RedirectResponse
    {
        $department->update(['active' => ! $department->active]);

        $this->audit->logLifecycle('department', $department->id, $department->active ? 'activated' : 'deactivated', $request->user());

        return back()->with('status', $department->active ? 'Department activated.' : 'Department deactivated.');
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

    private function duplicateExists(string $modelClass, string $name, ?int $excludeId = null): bool
    {
        return $modelClass::where('normalized_key', $modelClass::normalize($name))
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->exists();
    }
}
