<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('device_name');
            $table->string('region', 100)->nullable()->after('country');
            $table->string('city', 100)->nullable()->after('region');
            $table->string('timezone', 100)->nullable()->after('city');
            $table->string('isp', 150)->nullable()->after('timezone');
            $table->decimal('lat', 10, 7)->nullable()->after('isp');
            $table->decimal('lon', 10, 7)->nullable()->after('lat');

            $table->index(['country', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->dropIndex(['country', 'city']);
            $table->dropColumn(['country', 'region', 'city', 'timezone', 'isp', 'lat', 'lon']);
        });
    }
};
