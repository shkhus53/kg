<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'date', 'h_year', 'miqaat', 'remarks', 'status', 'closed_at', 'closed_by', 'is_reopened_for_correction', 'reopened_at', 'reopened_by'])]
class DutySession extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'is_reopened_for_correction' => 'boolean',
        ];
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    public function extraPresents(): HasMany
    {
        return $this->hasMany(ExtraPresent::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function reopenEvents(): HasMany
    {
        return $this->hasMany(SessionReopenEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Single source of truth for the status-badge colour, so "closed"
     * reads as red everywhere rather than drifting per-screen.
     */
    public function statusTone(): string
    {
        if ($this->is_reopened_for_correction) {
            return 'orange';
        }

        return match ($this->status) {
            'active' => 'green',
            'closed' => 'red',
            default => 'blue',
        };
    }
}
