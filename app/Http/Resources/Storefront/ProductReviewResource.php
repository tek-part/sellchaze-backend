<?php

namespace App\Http\Resources\Storefront;

use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductReview
 */
class ProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_product_id' => $this->store_product_id,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'author' => $this->author_name ?? $this->whenLoaded('customer', fn () => $this->customer?->name),
            // Additive keys the frozen frontend review mapper reads.
            'author_name' => $this->author_name ?? ($this->relationLoaded('customer') ? $this->customer?->name : null),
            'is_verified' => (bool) ($this->is_verified ?? false),
            'pros' => $this->pros,
            'cons' => $this->cons,
            'created_at' => $this->created_at,
        ];
    }
}
