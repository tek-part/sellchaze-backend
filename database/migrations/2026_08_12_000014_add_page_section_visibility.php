<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_page_sections', fn (Blueprint $table) => $table->boolean('is_visible')->default(true)->after('position'));
    }

    public function down(): void
    {
        Schema::table('store_page_sections', fn (Blueprint $table) => $table->dropColumn('is_visible'));
    }
};
