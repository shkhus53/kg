<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'normalized_key'])]
class Department extends Model
{
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }
}
