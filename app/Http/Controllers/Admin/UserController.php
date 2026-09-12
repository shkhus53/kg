<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\MasterDataAuditService;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Admin-only User Management. This is account administration, not
 * self-registration — every route here is gated by the `manage_users`
 * permission (admin only), and there is still no public registration
 * route anywhere in the application.
 *
 * Audit reuses MasterDataAuditService/MasterDataChangeLog (entity_type
 * 'user') rather than a second audit mechanism — the same append-only
 * pattern already used for Departments/Miqaats/Events/Venues.
 */
class UserController extends Controller
{
    public function __construct(private readonly MasterDataAuditService $audit) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $role = $request->query('role');
        $status = $request->query('status');

        $users = User::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(fn ($qq) => $qq->where('name', 'like', "%{$search}%")->orWhere('its_number', 'like', "%{$search}%"));
            })
            ->when($role, fn ($q) => $q->where('role', $role))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'its_number' => $validated['its_number'],
            // Login is ITS-based; email has no real use here but the column
            // is NOT NULL/unique — same synthetic placeholder convention as
            // app:create-admin.
            'email' => "its{$validated['its_number']}@no-email.local",
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'],
        ]);

        $this->audit->logLifecycle('user', $user->id, 'created', $request->user());

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'editedUser' => $user,
            'permissionGroups' => PermissionRegistry::groups(),
            'roleDefaults' => PermissionRegistry::defaultsForRole($user->role),
            'overrides' => $user->loadedOverrides(),
            'effectiveMap' => $user->effectivePermissionMap(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($request, $user, $validated) {
            if ($this->wouldLeaveNoActiveAdmin($user, $validated['role'], $validated['is_active'])) {
                return back()->withInput()->withErrors([
                    'role' => 'This is the last active Admin account — change rejected because it would leave the application without any Admin.',
                ]);
            }

            if ($request->user()->is($user) && ! $validated['is_active']) {
                return back()->withInput()->withErrors([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }

            $before = $user->only(['name', 'its_number', 'role', 'is_active']);
            $roleChanged = $before['role'] !== $validated['role'];
            $statusChanged = (bool) $before['is_active'] !== $validated['is_active'];

            $user->update($validated);

            $this->audit->logUpdate('user', $user->id, $before, $validated, $request->user());
            if ($roleChanged) {
                $this->audit->logLifecycle('user', $user->id, 'role_changed_to_'.$validated['role'], $request->user());
            }
            if ($statusChanged) {
                $this->audit->logLifecycle('user', $user->id, $validated['is_active'] ? 'activated' : 'deactivated', $request->user());
            }

            return redirect()->route('users.index')->with('status', 'User updated.');
        });
    }

    public function updatePassword(UpdateUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->update(['password' => Hash::make($request->validated()['password'])]);

        // The new password value never enters the audit trail — only the
        // fact that an Admin set it, and who.
        $this->audit->logLifecycle('user', $user->id, 'password_set_by_admin', $request->user());

        return redirect()->route('users.edit', $user)->with('status', 'Password updated.');
    }

    /**
     * Permission overrides — ALLOW/DENY/inherit per permission, for one
     * user. Admin is intentionally excluded: User::hasPermission() always
     * short-circuits to true for an admin regardless of any override row,
     * so persisting one would be dead configuration an Admin could
     * mistake for a real restriction (violates the Admin-safety
     * requirement that a DENY can never lock an Admin out of anything).
     */
    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['permissions' => 'Admin accounts always have full access — permission overrides do not apply and cannot be set.']);
        }

        $validated = $request->validate([
            'overrides' => ['required', 'array'],
            'overrides.*' => ['required', 'string', 'in:allow,deny,inherit'],
        ]);

        $actor = $request->user();
        $existing = $user->permissionOverrides()->pluck('effect', 'permission');

        DB::transaction(function () use ($validated, $user, $actor, $existing) {
            foreach ($validated['overrides'] as $permission => $desired) {
                if (! in_array($permission, PermissionRegistry::all(), true)) {
                    continue; // unknown permission key — ignore rather than trust an arbitrary client-supplied string
                }

                $current = $existing[$permission] ?? 'inherit';
                if ($current === $desired) {
                    continue; // no actual change — nothing to write or audit
                }

                if ($desired === 'inherit') {
                    UserPermissionOverride::where('user_id', $user->id)->where('permission', $permission)->delete();
                } else {
                    UserPermissionOverride::updateOrCreate(
                        ['user_id' => $user->id, 'permission' => $permission],
                        ['effect' => $desired, 'updated_by' => $actor->id, 'created_by' => $actor->id]
                    );
                }

                $this->audit->logUpdate(
                    'user_permission',
                    $user->id,
                    ['permission_'.$permission => $current],
                    ['permission_'.$permission => $desired],
                    $actor,
                );
            }
        });

        return redirect()->route('users.edit', $user)->with('status', 'Permissions updated.');
    }

    private function wouldLeaveNoActiveAdmin(User $user, string $newRole, bool $newIsActive): bool
    {
        $isCurrentlyActiveAdmin = $user->role === 'admin' && $user->is_active;
        $willRemainActiveAdmin = $newRole === 'admin' && $newIsActive;

        if (! $isCurrentlyActiveAdmin || $willRemainActiveAdmin) {
            return false;
        }

        return User::where('role', 'admin')->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->doesntExist();
    }
}
