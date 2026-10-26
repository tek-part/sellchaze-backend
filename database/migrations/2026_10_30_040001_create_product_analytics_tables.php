<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G4 (#49): per-product funnel analytics. Campaign labels are attached to raw
 * storefront events, the visitor session keeps its last campaign (so orders can
 * be attributed through `store_orders.analytics_session_hash`), and a daily
 * per-product rollup is incremented as events arrive so reports never scan raw
 * events. `storefront_session_products` gives each (session, step, product) one
 * unique row so "first in session" is decided by the database, not a count.
 * Additive only: existing rows and checkout totals are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_events', function (Blueprint $table) {
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
        });
        Schema::create('storefront_session_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('session_key', 64);
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'session_key'], 'sf_session_campaigns_unique');
            $table->index('updated_at', 'sf_session_campaigns_updated');
        });
        Schema::create('storefront_session_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('session_key', 64);
            $table->string('kind', 32);
            $table->unsignedBigInteger('product_id');
            $table->timestamp('created_at');
            $table->unique(['store_id', 'session_key', 'kind', 'product_id'], 'sf_session_products_unique');
            $table->index('created_at', 'sf_session_products_created');
        });
        Schema::create('store_product_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->date('day');
            // sha1 of the normalised campaign triple keeps the unique key short on MySQL.
            $table->char('segment', 40);
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('carts')->default(0);
            $table->unsignedInteger('cart_units')->default(0);
            $table->unsignedInteger('checkouts')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['store_id', 'day', 'product_id', 'segment'], 'sp_daily_metrics_unique');
            $table->index(['store_id', 'day', 'segment'], 'sp_daily_metrics_day_segment');
            $table->index(['store_id', 'product_id', 'day'], 'sp_daily_metrics_product_day');
        });
        // Session-campaign retention keeps rows that attribute an order; this makes that check an index probe.
        Schema::table('store_orders', fn (Blueprint $table) => $table->index(['store_id', 'analytics_session_hash'], 'store_orders_analytics_session'));
    }

    public function down(): void
    {
        if (DB::table('store_product_daily_metrics')->exists() || DB::table('storefront_session_campaigns')->exists()
            || DB::table('storefront_events')->whereNotNull('utm_source')->orWhereNotNull('utm_medium')->orWhereNotNull('utm_campaign')->exists()) {
            throw new RuntimeException('Preserve recorded product analytics and campaign labels before rollback.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropIndex('store_orders_analytics_session'));
        Schema::dropIfExists('store_product_daily_metrics');
        Schema::dropIfExists('storefront_session_products');
        Schema::dropIfExists('storefront_session_campaigns');
        Schema::table('storefront_events', fn (Blueprint $table) => $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']));
    }
};
