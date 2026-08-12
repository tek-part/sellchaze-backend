<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property \Illuminate\Support\Carbon|null $response_deadline
 */
class ProcurementRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_organization_id', 'target_supplier_organization_id', 'store_id', 'created_by_user_id', 'title',
        'description', 'quantity', 'unit', 'budget', 'currency', 'status',
        'response_deadline', 'awarded_quote_id', 'metadata', 'target_sector_id', 'items', 'attachments',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'budget' => 'decimal:2',
            'response_deadline' => 'datetime',
            'metadata' => 'array',
            'items' => 'array',
            'attachments' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function targetSupplierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_supplier_organization_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Organization, $this> */
    public function selectedSupplierOrganizations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'procurement_request_suppliers', 'procurement_request_id', 'supplier_organization_id')->withTimestamps();
    }

    /** @return BelongsTo<Sector, $this> */
    public function targetSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'target_sector_id');
    }

    /** @return HasMany<ProcurementQuote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(ProcurementQuote::class);
    }

    /** @return HasOne<ProcurementOrder, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(ProcurementOrder::class);
    }
}
