<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['entity_type', 'entity_id', 'field', 'old_value', 'new_value', 'changed_by', 'changed_at'])]
class MasterDataChangeLog extends Model
{
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
