<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-launch hardening — store_orders index optimization.
 *
 * Verified query patterns:
 *  - Merchant order list (MerchantOrderController): filters by store_id (+ optional
 *    status) and a placed_at date range → needs a store_id-leading placed_at index.
 *  - Analytics revenue (StoreAnalyticsService): WHERE store_id AND status='delivered'
 *    AND placed_at >= window → needs (store_id, status, placed_at).
 *  - Order status counts (analytics): GROUP BY status WHERE store_id → served by the
 *    (store_id, status) prefix of the new composite.
 *
 * Net change:
 *  + (store_id, placed_at)          — date-range queries without a status filter.
 *  + (store_id, status, placed_at)  — status+date queries (revenue) AND, via its
 *                                     leftmost prefix, everything (store_id, status)
 *                                     used to serve.
 *  - (store_id, status)             — dropped: strictly subsumed by the prefix above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->index(['store_id', 'placed_at']);
            $table->index(['store_id', 'status', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status', 'placed_at']);
            $table->dropIndex(['store_id', 'placed_at']);
            $table->index(['store_id', 'status']);
        });
    }
};
