<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6D: one row per redemption. Used to enforce global and per-customer
 * usage limits and to audit the discount actually applied to each order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'coupon_id']);
            $table->index(['coupon_id', 'store_customer_id']);
            $table->index('store_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
