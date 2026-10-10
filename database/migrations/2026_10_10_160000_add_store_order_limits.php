<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('order_limits')->nullable());
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('customer_phone_normalized', 32)->nullable();
            $table->index(['store_id', 'customer_phone_normalized', 'placed_at'], 'store_orders_phone_window');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex('store_orders_phone_window');
            $table->dropColumn('customer_phone_normalized');
        });
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('order_limits'));
    }
};
