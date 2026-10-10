<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // Retain the identifier if a variant is deleted: checkout must reject the line,
            // never silently turn the shopper's selection into the parent product.
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unique(['cart_id', 'store_product_id', 'variant_id'], 'cart_product_variant_unique');
            // Install the replacement first: MySQL requires an index for the cart FK.
            $table->dropUnique(['cart_id', 'store_product_id']);
        });
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('variant_name')->nullable();
            $table->json('variant_options')->nullable();
            $table->string('sku')->nullable();
        });
    }

    public function down(): void
    {
        // An older schema cannot represent multiple selections of the same product.
        // Refuse rollback rather than deleting or combining customers' cart lines.
        if (DB::table('cart_items')->select('cart_id', 'store_product_id')
            ->groupBy('cart_id', 'store_product_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve carts with multiple product variants before rolling back this migration.');
        }
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'store_product_id']);
            $table->dropUnique('cart_product_variant_unique');
            $table->dropColumn('variant_id');
        });
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropColumn(['variant_id', 'variant_name', 'variant_options', 'sku']);
        });
    }
};
