<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeLicensePurchaseEvent extends Model
{
    protected $fillable = [
        'theme_license_purchase_id', 'provider', 'provider_event_id',
        'event_type', 'status', 'error', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ThemeLicensePurchase::class, 'theme_license_purchase_id');
    }
}
