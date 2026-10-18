<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_funnels', fn (Blueprint $table) => $table->json('browser_pixels')->nullable());
    }

    public function down(): void
    {
        if (DB::table('store_funnels')->whereNotNull('browser_pixels')->exists()) {
            throw new RuntimeException('Cannot remove configured funnel pixels.');
        }
        Schema::table('store_funnels', fn (Blueprint $table) => $table->dropColumn('browser_pixels'));
    }
};
