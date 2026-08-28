<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'duty_session_id', 'uploaded_by', 'original_filename', 'file_type', 'status',
    'total_rows', 'valid_rows', 'invalid_rows', 'exact_duplicate_rows', 'cross_batch_duplicate_rows',
    'new_khidmatguzars', 'existing_khidmatguzars', 'new_departments', 'existing_departments',
    'error_summary',
])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'error_summary' => 'array',
        ];
    }

    public function dutySession(): BelongsTo
    {
        return $this->belongsTo(DutySession::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }
}
