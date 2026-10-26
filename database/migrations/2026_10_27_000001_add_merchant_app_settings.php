<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant App store: per-store settings for sales notifications, country restriction,
 * fake-order protection and product bundles, plus the protection rules that fired on an order.
 * Automated protection flags are recorded without a human actor.
 */
return new class extends Migration
{
    private const COLUMNS = ['social_proof_configuration', 'country_restriction_configuration', 'order_protection_configuration', 'bundles_configuration'];

    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->json($column)->nullable();
            }
        });
        Schema::table('store_orders', function (Blueprint $table) {
            $table->json('protection_signals')->nullable();
        });
        Schema::table('store_order_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $saved = DB::table('stores')->where(function ($query) {
            foreach (self::COLUMNS as $column) {
                $query->orWhereNotNull($column);
            }
        })->exists();
        if ($saved || DB::table('store_orders')->whereNotNull('protection_signals')->exists() || DB::table('store_order_reviews')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('Saved app settings and automated order reviews must be preserved. Export them before rollback.');
        }
        Schema::table('store_order_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
        });
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('protection_signals');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
