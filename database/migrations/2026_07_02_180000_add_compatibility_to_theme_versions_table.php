<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4D (Task 2): theme-version compatibility declarations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->string('max_platform_version', 32)->nullable()->after('min_platform_version');
            $table->json('supported_features')->nullable()->after('max_platform_version');
        });
    }

    public function down(): void
    {
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->dropColumn(['max_platform_version', 'supported_features']);
        });
    }
};
