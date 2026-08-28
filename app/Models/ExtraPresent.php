<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'duty_session_id', 'khidmatguzar_id', 'its_id_snapshot', 'full_name_snapshot',
    'department_id', 'department_name_snapshot', 'marked_by', 'marked_at', 'remark',
])]
class ExtraPresent extends Model
{
    protected function casts(): array
    {
        return ['marked_at' => 'datetime'];
    }

    public function khidmatguzar(): BelongsTo
    {
        return $this->belongsTo(Khidmatguzar::class);
    }

    public function dutySession(): BelongsTo
    {
        return $this->belongsTo(DutySession::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
