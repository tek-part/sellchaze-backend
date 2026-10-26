<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping and taxes v2. Purely additive: the legacy flat rate, free-over threshold,
 * regions and options on `stores` are untouched and keep serving checkout as the
 * implicit default zone until a store creates its first enabled zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_shipping_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->json('name');
            $table->json('countries');
            $table->json('regions');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['store_id', 'enabled', 'position']);
        });

        Schema::create('store_shipping_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('store_shipping_zones')->cascadeOnDelete();
            $table->json('name');
            $table->string('type', 10);
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->decimal('max_subtotal', 12, 2)->nullable();
            $table->unsignedInteger('min_weight_grams')->nullable();
            $table->unsignedInteger('max_weight_grams')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('free_over', 12, 2)->nullable();
            $table->json('delivery_time')->nullable();
            $table->decimal('cod_fee', 12, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['zone_id', 'enabled', 'position']);
        });

        Schema::create('store_tax_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('country', 2);
            $table->uuid('region_id')->nullable();
            $table->json('name');
            $table->decimal('rate', 6, 3);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'country']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('tax_shipping')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('tax_shipping');
        });
        Schema::dropIfExists('store_tax_overrides');
        Schema::dropIfExists('store_shipping_rates');
        Schema::dropIfExists('store_shipping_zones');
    }
};
