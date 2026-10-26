<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfillments: one row per parcel handed to a carrier, covering some units of some lines.
 * `fulfilled_quantity` counts units whose stock was committed (deducted) by a fulfillment;
 * `released_quantity` counts units that will never ship (order cancelled, refunded before shipping).
 * Units still to fulfil = quantity - fulfilled_quantity - released_quantity.
 *
 * Schema only, and re-runnable: MySQL DDL auto-commits, so every step checks what already exists.
 * Historic data is filled in by 2026_10_28_000003_backfill_store_order_fulfillment_counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_order_fulfillments')) {
            Schema::create('store_order_fulfillments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
                $table->string('status', 20)->default('in_transit');
                $table->string('carrier', 20);
                $table->string('carrier_name', 100)->nullable();
                $table->string('tracking_number', 100)->nullable();
                // Merchant override; when null the URL is computed from the carrier template.
                $table->string('custom_tracking_url', 500)->nullable();
                // [{store_order_item_id, quantity}]
                $table->json('items');
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->boolean('notify_customer')->default(true);
                $table->text('note')->nullable();
                $table->string('source', 20)->default('manual');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->foreignId('store_shipment_id')->nullable()->constrained('store_shipments')->nullOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();

                $table->index(['store_id', 'store_order_id', 'id'], 'store_fulfillments_order_index');
            });
        }

        foreach (['fulfilled_quantity', 'released_quantity'] as $column) {
            if (! Schema::hasColumn('store_order_items', $column)) {
                Schema::table('store_order_items', fn (Blueprint $table) => $table->unsignedInteger($column)->default(0));
            }
        }

        if (! Schema::hasColumn('store_orders', 'fulfillment_status')) {
            Schema::table('store_orders', fn (Blueprint $table) => $table->string('fulfillment_status', 20)->default('unfulfilled'));
        }
        if (! Schema::hasIndex('store_orders', 'store_orders_fulfillment_status_index')) {
            Schema::table('store_orders', fn (Blueprint $table) => $table->index(['store_id', 'fulfillment_status'], 'store_orders_fulfillment_status_index'));
        }
    }

    /**
     * Forward-only once partial shipments exist: the previous code settles every unit of a reserved
     * line again, so a line that is partly shipped or partly released would be deducted or released twice.
     */
    public function down(): void
    {
        if (Schema::hasColumn('store_order_items', 'fulfilled_quantity') && Schema::hasColumn('store_order_items', 'released_quantity')) {
            $split = DB::table('store_order_items')->whereNull('superseded_at')
                ->where(fn ($query) => $query->where(fn ($partial) => $partial->where('fulfilled_quantity', '>', 0)->whereColumn('fulfilled_quantity', '<', 'quantity'))
                    ->orWhere(fn ($held) => $held->where('inventory_status', 'reserved')->where('released_quantity', '>', 0)))
                ->count();
            if ($split > 0) {
                throw new RuntimeException("Cannot roll back fulfillments: {$split} order line(s) are partly shipped or partly released, and the previous inventory code would deduct or release them twice. Fulfil, cancel or refund those lines first, or keep this migration.");
            }
        }

        if (Schema::hasIndex('store_orders', 'store_orders_fulfillment_status_index')) {
            Schema::table('store_orders', fn (Blueprint $table) => $table->dropIndex('store_orders_fulfillment_status_index'));
        }
        if (Schema::hasColumn('store_orders', 'fulfillment_status')) {
            Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('fulfillment_status'));
        }
        foreach (['fulfilled_quantity', 'released_quantity'] as $column) {
            if (Schema::hasColumn('store_order_items', $column)) {
                Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        Schema::dropIfExists('store_order_fulfillments');
    }
};
