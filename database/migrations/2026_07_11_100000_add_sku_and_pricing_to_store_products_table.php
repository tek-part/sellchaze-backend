<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 prep — catalog foundation. Adds SKU/barcode (inventory-ready),
 * compare_price (merchandising), and short_description to store products.
 * SKU is unique per store (nullable — MySQL allows multiple NULLs, so existing
 * rows are unaffected and SKU stays optional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('slug');
            $table->string('barcode')->nullable()->after('sku');
            $table->string('short_description', 500)->nullable()->after('description');
            $table->decimal('compare_price', 12, 2)->nullable()->after('price');

            $table->unique(['store_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'sku']);
            $table->dropColumn(['sku', 'barcode', 'short_description', 'compare_price']);
        });
    }
};
