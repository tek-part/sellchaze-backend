<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: storefront order line items. Fully snapshotted (name, unit_price,
 * quantity, line_total) so the order is immutable even if the product changes
 * or is deleted later. Store-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();

            $table->index('store_id');
            $table->index('store_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_order_items');
    }
};
