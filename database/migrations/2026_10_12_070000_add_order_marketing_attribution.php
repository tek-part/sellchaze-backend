<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', fn (Blueprint $table) => $table->json('marketing_attribution')->nullable());
    }

    public function down(): void
    {
        if (DB::table('store_orders')->whereNotNull('marketing_attribution')->exists()) {
            throw new RuntimeException('Preserve order campaign snapshots before rollback.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('marketing_attribution'));
    }
};
