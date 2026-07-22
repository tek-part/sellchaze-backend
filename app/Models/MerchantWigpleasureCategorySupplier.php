<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantWigpleasureCategorySupplier extends Model
{
    protected $table = 'merchant_wigpleasure_category_suppliers';

    protected $fillable = [
        'merchant_id',
        'wigpleasure_category_id',
        'supplier_user_id',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }
}
