<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: storefront orders. Entirely separate from the legacy B2B `orders`
 * table (the Phase 3.5 ratified decision). Store-scoped; order_number is unique
 * per store. Customer contact + shipping address are snapshotted for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->string('order_number');
            $table->string('status', 20)->default('pending'); // pending|confirmed|processing|shipped|delivered|cancelled
            $table->string('currency', 3)->default('USD');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->json('shipping_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'order_number']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'store_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
