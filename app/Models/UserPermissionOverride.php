<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (user, permission) with an explicit 'allow' or 'deny' — the
 * ABSENCE of a row means "inherit the role default", not "deny". See
 * User::hasPermission() for the full precedence: admin > user override >
 * role default.
 */
#[Fillable(['user_id', 'permission', 'effect', 'created_by', 'updated_by'])]
class UserPermissionOverride extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
