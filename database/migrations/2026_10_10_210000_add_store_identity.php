<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('site_title')->nullable();
            $table->string('header_mode', 10)->nullable();
            $table->string('header_text', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('font_family', 80)->nullable();
            $table->text('favicon')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['site_title', 'header_mode', 'header_text', 'primary_color', 'font_family', 'favicon']));
    }
};
