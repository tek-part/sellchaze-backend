<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('checkout_fields')->nullable());
        Schema::table('store_orders', fn (Blueprint $table) => $table->string('customer_email')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('store_orders')->whereNull('customer_email')->exists()) {
            throw new RuntimeException('Cannot remove phone-first checkout while orders without email exist.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->string('customer_email')->nullable(false)->change());
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('checkout_fields'));
    }
};
