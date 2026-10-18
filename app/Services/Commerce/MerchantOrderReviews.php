<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;

class MerchantOrderReviews
{
    public const CLASSIFICATIONS = ['high_risk', 'moderate_risk', 'spam'];

    /** Caller holds the store and order locks. Classification never changes fulfillment or payment. */
    public function classify(StoreOrder $row, ?string $classification, int $version, int $actorId, ?string $note = null): bool
    {
        $review = DB::table('store_order_reviews')->where('store_id', $row->store_id)->where('store_order_id', $row->id)->lockForUpdate()->first();
        // A retry of this exact version and target is harmless; stale edits to another target are rejected.
        if ($review && $review->classification === $classification && $review->version === $version + 1 && (int) $review->actor_id === $actorId) {
            return false;
        }
        abort_unless(($review->version ?? 0) === $version, 409, 'Review changed. Reload before updating it.');
        if (($review->classification ?? null) === $classification) {
            return false;
        }
        DB::table('store_order_reviews')->updateOrInsert(['store_id' => $row->store_id, 'store_order_id' => $row->id], [
            'classification' => $classification, 'version' => $version + 1, 'actor_id' => $actorId,
            'created_at' => $review->created_at ?? now(), 'updated_at' => now(),
        ]);
        $row->statusChanges()->create(['store_id' => $row->store_id, 'from_status' => $row->status, 'to_status' => $row->status,
            'actor_id' => $actorId, 'source' => 'merchant_review_'.($classification ?? 'clear'), 'notes' => $note]);

        return true;
    }
}
