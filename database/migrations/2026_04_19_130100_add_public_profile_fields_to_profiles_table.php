<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('profiles', 'cover_photo')) {
                $table->string('cover_photo')->nullable()->after('photo');
            }
            if (!Schema::hasColumn('profiles', 'website')) {
                $table->string('website')->nullable()->after('cover_photo');
            }
            if (!Schema::hasColumn('profiles', 'tagline')) {
                $table->string('tagline', 191)->nullable()->after('website');
            }
            if (!Schema::hasColumn('profiles', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('tagline');
                $table->index('is_public');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['is_public']);
            $table->dropColumn(['cover_photo', 'website', 'tagline', 'is_public']);
        });
    }
};
