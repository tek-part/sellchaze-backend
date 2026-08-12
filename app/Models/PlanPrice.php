<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $fillable = [
        'plan_id', 'country_code', 'currency', 'billing_cycle', 'amount',
        'tax_inclusive', 'quote_required', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'quote_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
