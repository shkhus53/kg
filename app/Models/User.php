<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\PermissionRegistry;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'its_number', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public const ROLES = ['admin', 'operator', 'viewer'];

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /**
     * True for as long as ANY session-lifecycle permission is granted —
     * used only to decide whether Blade shows the relevant buttons
     * (Create/Activate/Close). The actual authorization for each action is
     * always its own specific `can:` gate, never this helper.
     */
    public function canManageSessions(): bool
    {
        return $this->hasPermission('create_sessions')
            || $this->hasPermission('activate_sessions')
            || $this->hasPermission('close_sessions');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /**
     * Effective-permission precedence, non-negotiable order:
     *   1. Admin -> always true. An Admin can never be restricted by a
     *      user-level DENY (see the Admin-safety requirement) — this
     *      short-circuit is what guarantees that regardless of what rows
     *      might exist in user_permission_overrides for this account.
     *   2. Explicit user override (DENY beats ALLOW is not a real case —
     *      there is at most one row per (user, permission) thanks to the
     *      DB unique constraint — so "the override" is simply applied,
     *      whichever effect it is).
     *   3. Role default.
     *
     * Overrides are loaded once per request and cached on the instance —
     * `auth()->user()` returns the same instance for the life of a
     * request, so this is one query total per request for a non-admin,
     * zero for an admin (short-circuited before the query ever runs).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $override = $this->loadedOverrides()[$permission] ?? null;
        if ($override !== null) {
            return $override === 'allow';
        }

        return in_array($permission, PermissionRegistry::defaultsForRole($this->role), true);
    }

    /**
     * @return array<string,string> permission => 'allow'|'deny'
     */
    public function loadedOverrides(): array
    {
        if (! $this->relationLoaded('permissionOverrides')) {
            $this->setRelation('permissionOverrides', $this->permissionOverrides()->get());
        }

        return $this->permissionOverrides->pluck('effect', 'permission')->all();
    }

    /**
     * @return array<string,array{source:string,effective:bool}> every known
     *                                                           permission, its effective value, and why (admin/override/role)
     */
    public function effectivePermissionMap(): array
    {
        $overrides = $this->isAdmin() ? [] : $this->loadedOverrides();
        $defaults = $this->isAdmin() ? [] : PermissionRegistry::defaultsForRole($this->role);

        $map = [];
        foreach (PermissionRegistry::all() as $permission) {
            if ($this->isAdmin()) {
                $map[$permission] = ['source' => 'admin', 'effective' => true];

                continue;
            }

            if (isset($overrides[$permission])) {
                $map[$permission] = ['source' => 'override_'.$overrides[$permission], 'effective' => $overrides[$permission] === 'allow'];

                continue;
            }

            $isDefault = in_array($permission, $defaults, true);
            $map[$permission] = ['source' => $isDefault ? 'role_default' : 'role_absent', 'effective' => $isDefault];
        }

        return $map;
    }
}
