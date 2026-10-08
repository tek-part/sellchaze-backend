<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            $table->string('hosting_status', 24)->nullable();
            $table->string('hosting_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_domains', fn (Blueprint $table) => $table->dropColumn(['hosting_status', 'hosting_error']));
    }
};
