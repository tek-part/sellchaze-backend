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
 */
return new class extends Migration
{
    /** Statuses that mean everything that was going to ship already shipped (the legacy all-at-once flow). */
    private const SHIPPED = ['shipped', 'delivered', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'];

    public function up(): void
    {
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

        Schema::table('store_order_items', function (Blueprint $table) {
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('released_quantity')->default(0);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)->default('unfulfilled');
            $table->index(['store_id', 'fulfillment_status'], 'store_orders_fulfillment_status_index');
        });

        // Backfill: orders that already shipped were shipped all at once.
        $shipped = DB::table('store_orders')->whereIn('status', self::SHIPPED)->select('id');
        DB::table('store_order_items')->whereNull('digital_delivery')->whereIn('store_order_id', $shipped)->update(['fulfilled_quantity' => DB::raw('quantity')]);
        DB::table('store_orders')->whereIn('status', self::SHIPPED)->update(['fulfillment_status' => 'fulfilled']);
        $cancelled = DB::table('store_orders')->where('status', 'cancelled')->select('id');
        DB::table('store_order_items')->where(fn ($query) => $query->where('inventory_status', 'released')->orWhereIn('store_order_id', $cancelled))
            ->update(['released_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex('store_orders_fulfillment_status_index');
            $table->dropColumn('fulfillment_status');
        });
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn(['fulfilled_quantity', 'released_quantity']));
        Schema::dropIfExists('store_order_fulfillments');
    }
};
