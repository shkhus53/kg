<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'normalized_key', 'code', 'description', 'active', 'sort_order'])]
class Miqaat extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
