<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6D: an active cart may carry an applied coupon until checkout. Additive
 * and nullable — existing carts are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('store_customer_id')
                ->constrained('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn('coupon_id');
        });
    }
};
