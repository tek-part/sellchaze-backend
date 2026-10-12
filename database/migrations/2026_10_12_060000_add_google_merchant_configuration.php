<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('google_merchant_configuration')->nullable());
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('google_merchant_configuration')->exists()) {
            throw new RuntimeException('Preserve configured merchant information before rollback.');
        }
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('google_merchant_configuration'));
    }
};
