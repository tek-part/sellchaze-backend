<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->json('landing_page')->nullable());
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('landing_page')->exists()) {
            throw new RuntimeException('Preserve configured product landing pages before rollback.');
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('landing_page'));
    }
};
