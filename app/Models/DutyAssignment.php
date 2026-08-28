<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'duty_session_id', 'import_batch_id', 'khidmatguzar_id', 'department_id',
    'source_row_number', 'assignment_fingerprint',
    'block_name', 'day', 'day_alias', 'seat', 'category', 'venue_name_raw',
    'current_status', 'attendance_marked_at', 'attendance_marked_by',
    'full_name_snapshot', 'gender_snapshot', 'age_snapshot', 'idara_snapshot', 'jamaat_snapshot', 'jamiaat_snapshot',
    'h_year', 'miqaat',
    'status_raw', 'allocated_user_name', 'allocated_date', 'deallocated_user_name', 'deallocated_date',
    'scanned', 'acc_child_below_5yrs', 'multiple_acc_child_above_4yrs',
])]
class DutyAssignment extends Model
{
    protected function casts(): array
    {
        return ['attendance_marked_at' => 'datetime'];
    }

    public function dutySession(): BelongsTo
    {
        return $this->belongsTo(DutySession::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function khidmatguzar(): BelongsTo
    {
        return $this->belongsTo(Khidmatguzar::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendanceMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}
