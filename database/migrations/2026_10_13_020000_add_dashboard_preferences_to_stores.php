<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('dashboard_preferences')->nullable());
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('dashboard_preferences')->exists()) {
            throw new RuntimeException('Saved dashboard preferences must be preserved.');
        }
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('dashboard_preferences'));
    }
};
