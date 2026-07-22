<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: per-customer wishlist. Store-scoped; one row per (customer, product).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_customer_id', 'store_product_id']);
            $table->index(['store_id', 'store_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
