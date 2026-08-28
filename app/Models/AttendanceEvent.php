<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'duty_assignment_id', 'duty_session_id', 'khidmatguzar_id', 'action', 'context',
    'performed_by', 'performed_at', 'remark',
])]
class AttendanceEvent extends Model
{
    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    public function dutyAssignment(): BelongsTo
    {
        return $this->belongsTo(DutyAssignment::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
