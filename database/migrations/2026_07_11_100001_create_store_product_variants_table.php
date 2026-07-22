<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 prep — minimal, extensible product variant foundation.
 *
 *   Product └── Variants (SKU, price override, active, metadata)
 *
 * Deliberately not Shopify-level: `options` (json) holds arbitrary attributes
 * (size/color/storage/...), `weight` is shipping-ready, and future Phase 7 work
 * attaches inventory/tax to a variant. SKU is unique per store (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('name'); // e.g. "Red / Large"
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price_override', 12, 2)->nullable(); // null = inherit product price
            $table->decimal('weight', 10, 3)->nullable();         // shipping-ready (kg)
            $table->json('options')->nullable();                  // { size: 'L', color: 'Red', ... }
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'sku']);
            $table->index(['store_id', 'store_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_variants');
    }
};
