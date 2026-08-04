<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile cover accent color (folded in from the Sellchase-api lineage). The
 * frontend already sends/reads profile.cover_color; this adds the column so it
 * is persisted and returned instead of silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'cover_color')) {
                $table->string('cover_color', 32)->nullable()->after('cover_photo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (Schema::hasColumn('profiles', 'cover_color')) {
                $table->dropColumn('cover_color');
            }
        });
    }
};
