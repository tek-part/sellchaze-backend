<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: cart line items. `name` + `unit_price` are snapshots taken at add
 * time; the FK to store_products is kept for re-validation and set null if the
 * product is later deleted. Store-scoped for defense-in-depth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['cart_id', 'store_product_id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
