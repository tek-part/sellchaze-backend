<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4D (Task 6): marketplace catalog fields. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_featured')->default(false)->after('is_marketplace');
            $table->unsignedInteger('installs_count')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_featured', 'installs_count']);
        });
    }
};
