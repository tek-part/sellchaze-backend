<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons become discounts (Shopify-style): code or automatic, fixed / percentage / free shipping /
 * buy X get Y, scoped to all products, chosen products or categories, with a minimum quantity.
 * Orders keep the list of discounts that applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->change();
            $table->string('method', 12)->default('code')->after('store_id');
            $table->string('title', 120)->nullable()->after('code');
            $table->string('applies_to', 12)->default('all')->after('value');
            $table->json('target_ids')->nullable()->after('applies_to');
            $table->unsignedInteger('minimum_quantity')->nullable()->after('minimum_order_amount');
            $table->json('bxgy')->nullable()->after('minimum_quantity');
            $table->index(['store_id', 'method', 'is_active']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->json('discount_details')->nullable()->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('discount_details');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'method', 'is_active']);
            $table->dropColumn(['method', 'title', 'applies_to', 'target_ids', 'minimum_quantity', 'bxgy']);
        });
    }
};
