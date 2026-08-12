<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementOrder extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_number', 'procurement_request_id', 'procurement_quote_id',
        'buyer_organization_id', 'supplier_organization_id', 'store_id',
        'created_by_user_id', 'title', 'quantity', 'unit', 'total', 'currency',
        'status', 'expected_delivery_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'total' => 'decimal:2',
            'expected_delivery_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ProcurementRequest, $this> */
    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class);
    }

    /** @return BelongsTo<ProcurementQuote, $this> */
    public function procurementQuote(): BelongsTo
    {
        return $this->belongsTo(ProcurementQuote::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function supplierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }
}
