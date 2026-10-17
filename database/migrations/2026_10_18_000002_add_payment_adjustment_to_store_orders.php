<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', fn (Blueprint $table) => $table->json('payment_adjustment')->nullable());
    }

    public function down(): void
    {
        if (DB::table('store_orders')->whereNotNull('payment_adjustment')->exists()) {
            throw new RuntimeException('Payment adjustment snapshots must be retained.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('payment_adjustment'));
    }
};
