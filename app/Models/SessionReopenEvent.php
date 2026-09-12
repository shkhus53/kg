<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule 6 audit trail: one row per Admin reopen of a Closed session. Never
 * edited to rewrite history — closeSession() only stamps closed_at onto the
 * currently-open row when the session is closed again.
 */
#[Fillable(['duty_session_id', 'reopened_by', 'reason', 'detail', 'reopened_at', 'closed_at'])]
class SessionReopenEvent extends Model
{
    public const REASONS = [
        'attendance_correction_required' => 'Attendance correction required',
        'incorrect_attendance_marked' => 'Incorrect attendance marked',
        'assignment_correction_required' => 'Assignment correction required',
        'duty_list_import_correction' => 'Duty list/import correction',
        'session_closed_accidentally' => 'Session closed accidentally',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return ['reopened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function dutySession(): BelongsTo
    {
        return $this->belongsTo(DutySession::class);
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
