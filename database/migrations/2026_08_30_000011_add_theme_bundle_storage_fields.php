<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->string('bundle_disk', 32)->nullable()->after('bundle_url');
            $table->string('bundle_path')->nullable()->after('bundle_disk');
            $table->string('bundle_checksum', 64)->nullable()->after('bundle_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->dropIndex(['bundle_checksum']);
            $table->dropColumn(['bundle_disk', 'bundle_path', 'bundle_checksum']);
        });
    }
};
