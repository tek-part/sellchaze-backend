<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('quantity_offers')->nullable();
            $table->unsignedInteger('quantity_offers_version')->default(1);
        });
        Schema::table('cart_items', fn (Blueprint $table) => $table->json('quantity_offer')->nullable());
        Schema::table('store_order_items', fn (Blueprint $table) => $table->json('quantity_offer')->nullable());
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('quantity_offers')->exists() || DB::table('cart_items')->whereNotNull('quantity_offer')->exists() || DB::table('store_order_items')->whereNotNull('quantity_offer')->exists()) {
            throw new RuntimeException('Preserve configured offers and historical snapshots before rolling back.');
        }
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn('quantity_offer'));
        Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('quantity_offer'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['quantity_offers', 'quantity_offers_version']));
    }
};
