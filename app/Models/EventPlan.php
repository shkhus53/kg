<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4: a persistent planning record bridging the Phase 3 forecast and
 * an eventual DutySession. `forecast_snapshot` is frozen at creation time —
 * the exact recommendation the operator saw — and is never recalculated,
 * so a plan's history stays stable even if forecasting data or logic
 * changes later. `departments` is the mutable Phase 4 working set (each
 * row's `recommended` is copied once from the snapshot; only `planned`
 * ever changes after creation). See EventPlanningService for both shapes.
 */
#[Fillable([
    'miqaat_id', 'event_id', 'venue_id', 'planned_date', 'h_year', 'status',
    'forecast_snapshot', 'departments', 'recommended_total', 'planned_total',
    'duty_session_id', 'created_by',
])]
class EventPlan extends Model
{
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'forecast_snapshot' => 'array',
            'departments' => 'array',
        ];
    }

    public function miqaat(): BelongsTo
    {
        return $this->belongsTo(Miqaat::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function dutySession(): BelongsTo
    {
        return $this->belongsTo(DutySession::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /**
     * Overall plan-vs-recommendation status, mirroring the per-department
     * classification in EventPlanningService::planningStatus() at the
     * headline level.
     */
    public function planningStatus(): string
    {
        return EventPlan::classify($this->planned_total, $this->recommended_total);
    }

    public static function classify(int $planned, int $recommended): string
    {
        if ($recommended > 0 && $planned === 0) {
            return 'missing_from_plan';
        }

        if ($recommended === 0) {
            return $planned > 0 ? 'over_planned' : 'adequately_planned';
        }

        $diffPct = abs($planned - $recommended) / $recommended;
        if ($diffPct <= 0.1) {
            return 'adequately_planned';
        }

        return $planned < $recommended ? 'under_planned' : 'over_planned';
    }
}
