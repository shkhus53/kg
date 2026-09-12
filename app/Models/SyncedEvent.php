<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'duty_session_id', 'assignment_id', 'khidmatguzar_id',
    'action', 'context', 'payload',
    'operator_user_id', 'synced_by_user_id', 'device_id',
    'local_sequence', 'local_timestamp', 'package_version',
    'attempt_count', 'first_received_at', 'last_attempted_at', 'last_result', 'detail', 'synced_at',
    'attendance_event_id', 'extra_present_id',
])]
class SyncedEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'local_timestamp' => 'datetime',
            'first_received_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function syncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'synced_by_user_id');
    }

    public function attendanceEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    public function extraPresent(): BelongsTo
    {
        return $this->belongsTo(ExtraPresent::class);
    }
}
