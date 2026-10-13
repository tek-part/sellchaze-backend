<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->json('social_links_configuration')->nullable());
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('social_links_configuration')->exists()) {
            throw new RuntimeException('Saved social links must be preserved.');
        }
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('social_links_configuration'));
    }
};
