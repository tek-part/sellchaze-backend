<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('downsell_configuration')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('downsell_configuration')->exists()) {
            throw new RuntimeException('Saved downsell settings must be preserved.');
        }
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('downsell_configuration');
        });
    }
};
