<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-language menus (E4): `label_i18n` holds `{locale: label}`; the existing
 * `label` string stays the default-locale pick so every legacy reader keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_menu_items', function (Blueprint $table) {
            $table->json('label_i18n')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('store_menu_items', function (Blueprint $table) {
            $table->dropColumn('label_i18n');
        });
    }
};
