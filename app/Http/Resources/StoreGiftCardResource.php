<?php

namespace App\Http\Resources;

use App\Models\StoreGiftCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the code or its digest: only the last four characters.
 *
 * @mixin StoreGiftCard
 */
class StoreGiftCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'last4' => $this->last4,
            'masked_code' => '•••• '.$this->last4,
            'initial_value' => $this->initial_value,
            'balance' => $this->balance,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'state' => $this->resource->state(),
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'note' => $this->note,
            'source' => $this->source,
            'issued_by' => $this->issued_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
