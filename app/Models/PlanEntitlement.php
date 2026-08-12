<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanEntitlement extends Model
{
    protected $fillable = ['plan_id', 'entitlement_id', 'value_boolean', 'value_integer', 'value_text'];

    protected function casts(): array
    {
        return ['value_boolean' => 'boolean', 'value_integer' => 'integer'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Entitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }
}
