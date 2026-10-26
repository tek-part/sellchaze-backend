<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposits (عربون): a store may require part of the order to be paid online, the rest cash on delivery.
 * Off by default (no configuration means no deposit); existing orders keep a null deposit and are unchanged.
 * `deposit_total` is what the order's payment method collects first; `deposit_details` freezes the rule used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('deposit_configuration')->nullable();
        });
        Schema::table('store_orders', function (Blueprint $table) {
            $table->decimal('deposit_total', 12, 2)->nullable();
            $table->json('deposit_details')->nullable();
            $table->timestamp('deposit_paid_at')->nullable();
            $table->timestamp('remainder_collected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['deposit_total', 'deposit_details', 'deposit_paid_at', 'remainder_collected_at']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('deposit_configuration');
        });
    }
};
