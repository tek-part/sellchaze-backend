<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream D: tag every B2B order with its origin (`source`) and link
 * storefront-bridged rows back to the StoreOrder they mirror.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'source')) {
                $table->string('source', 32)->default('merchant_direct')->index()->after('status');
            }
            if (! Schema::hasColumn('orders', 'store_id')) {
                $table->foreignId('store_id')->nullable()->after('source')
                    ->constrained('stores')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'store_order_id')) {
                $table->foreignId('store_order_id')->nullable()->unique()->after('store_id')
                    ->constrained('store_orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'storefront_items')) {
                $table->json('storefront_items')->nullable()->after('wigpleasure_products');
            }
        });

        // Backfill: rows synced from the external Wigpleasure store are `external_store`.
        if (Schema::hasColumn('orders', 'wigpleasure_order_id')) {
            DB::table('orders')
                ->whereNotNull('wigpleasure_order_id')
                ->where('source', 'merchant_direct')
                ->update(['source' => 'external_store']);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'store_order_id')) {
                $table->dropConstrainedForeignId('store_order_id');
            }
            if (Schema::hasColumn('orders', 'store_id')) {
                $table->dropConstrainedForeignId('store_id');
            }
            if (Schema::hasColumn('orders', 'storefront_items')) {
                $table->dropColumn('storefront_items');
            }
            if (Schema::hasColumn('orders', 'source')) {
                $table->dropIndex(['source']);
                $table->dropColumn('source');
            }
        });
    }
};
