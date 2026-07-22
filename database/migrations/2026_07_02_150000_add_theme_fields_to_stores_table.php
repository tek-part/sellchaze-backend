<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.5 (Task 4): minimal theme-readiness columns on stores.
 * No themes table, no FK, no rendering — just the architectural seam so a
 * future theme system attaches without reshaping the stores table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('theme_id')->nullable()->after('status');
            $table->json('theme_settings')->nullable()->after('theme_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['theme_id', 'theme_settings']);
        });
    }
};
