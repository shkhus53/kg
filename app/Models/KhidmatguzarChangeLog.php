<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per master-data field actually changed by a re-import (Rule 3:
 * latest non-blank Excel value wins, blank never overwrites). This is the
 * audit trail the prior system lacked — without it, a bad upload could
 * silently corrupt a person's name/gender/idara with no way to see what
 * changed or who did it.
 */
#[Fillable(['khidmatguzar_id', 'import_batch_id', 'field', 'old_value', 'new_value', 'changed_at'])]
class KhidmatguzarChangeLog extends Model
{
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function khidmatguzar(): BelongsTo
    {
        return $this->belongsTo(Khidmatguzar::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
