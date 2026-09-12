<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'date', 'h_year', 'miqaat', 'remarks', 'status', 'closed_at', 'closed_by', 'is_reopened_for_correction', 'reopened_at', 'reopened_by', 'miqaat_id', 'event_id', 'venue_id'])]
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

    public function miqaatRef(): BelongsTo
    {
        return $this->belongsTo(Miqaat::class, 'miqaat_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function eventPlan(): HasOne
    {
        return $this->hasOne(EventPlan::class);
    }

    /**
     * True only for a session created through the Phase 2 structured flow
     * (Miqaat → Event → Venue). A "legacy" session — created before this
     * phase, or via the free-text fallback — has none of these and is a
     * permanently valid state, never backfilled or fabricated.
     */
    public function hasStructuredIdentity(): bool
    {
        return $this->miqaat_id !== null && $this->event_id !== null && $this->venue_id !== null;
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
