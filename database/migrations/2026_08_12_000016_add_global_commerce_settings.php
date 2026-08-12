<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('default_locale', 5)->default('en')->after('currency');
            $table->json('supported_locales')->nullable()->after('default_locale');
            $table->json('supported_currencies')->nullable()->after('supported_locales');
            $table->string('timezone', 64)->default('UTC')->after('supported_currencies');
            $table->boolean('tax_enabled')->default(false)->after('timezone');
            $table->decimal('tax_rate', 6, 3)->default(0)->after('tax_enabled');
            $table->boolean('tax_prices_include')->default(false)->after('tax_rate');
            $table->boolean('shipping_enabled')->default(false)->after('tax_prices_include');
            $table->decimal('shipping_flat_rate', 12, 2)->default(0)->after('shipping_enabled');
            $table->decimal('shipping_free_over', 12, 2)->nullable()->after('shipping_flat_rate');
        });
        Schema::table('store_orders', fn (Blueprint $table) => $table->decimal('tax_total', 12, 2)->default(0)->after('discount_total'));
    }

    public function down(): void
    {
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('tax_total'));
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn([
            'default_locale', 'supported_locales', 'supported_currencies', 'timezone',
            'tax_enabled', 'tax_rate', 'tax_prices_include', 'shipping_enabled',
            'shipping_flat_rate', 'shipping_free_over',
        ]));
    }
};
