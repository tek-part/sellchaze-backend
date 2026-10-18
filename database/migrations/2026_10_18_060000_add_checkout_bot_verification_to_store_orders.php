<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null preserves unknown results for historical/manual orders. Never backfill false.
        Schema::table('store_orders', fn (Blueprint $table) => $table->boolean('checkout_bot_verified')->nullable());
    }

    public function down(): void
    {
        if (DB::table('store_orders')->whereNotNull('checkout_bot_verified')->exists()) {
            throw new RuntimeException('Checkout bot verification snapshots must be retained.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('checkout_bot_verified'));
    }
};
