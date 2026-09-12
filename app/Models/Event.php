<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['miqaat_id', 'name', 'normalized_key', 'code', 'family', 'description', 'active', 'sort_order'])]
class Event extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Event identity is scoped per Miqaat (approved decision) — the same
     * normalized name under two different Miqaats is two legitimate rows,
     * so this only normalizes the text, it does not dedupe globally.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    public function miqaat(): BelongsTo
    {
        return $this->belongsTo(Miqaat::class);
    }
}
