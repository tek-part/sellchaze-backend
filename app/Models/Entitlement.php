<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entitlement extends Model
{
    protected $fillable = ['key', 'kind', 'unit', 'name_en', 'name_ar', 'description_en', 'description_ar'];

    /** @return HasMany<PlanEntitlement, $this> */
    public function planValues(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }
}
