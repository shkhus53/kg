<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['its_id', 'full_name', 'gender', 'idara', 'jamaat', 'jamiaat'])]
class Khidmatguzar extends Model
{
    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }

    public function extraPresents(): HasMany
    {
        return $this->hasMany(ExtraPresent::class);
    }
}
