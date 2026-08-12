<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $version
 * @property string|null $delivery_terms
 */
class ProcurementQuote extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'procurement_request_id', 'supplier_organization_id', 'submitted_by_user_id',
        'amount', 'currency', 'lead_time_days', 'valid_until', 'notes', 'status',
        'version', 'delivery_terms', 'attachments',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'valid_until' => 'date', 'version' => 'integer', 'attachments' => 'array'];
    }

    /** @return BelongsTo<ProcurementRequest, $this> */
    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function supplierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    /** @return HasOne<ProcurementOrder, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(ProcurementOrder::class);
    }
}
