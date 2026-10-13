<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('head_code_configuration')->nullable());
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('head_code_configuration')->exists()) {
            throw new RuntimeException('Published merchant head code must be preserved.');
        }
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('head_code_configuration'));
    }
};
