<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementAuditEntry extends Model
{
    protected $fillable = [
        'procurement_request_id', 'procurement_quote_id', 'procurement_order_id', 'actor_user_id',
        'event', 'from_status', 'to_status', 'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
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
}
