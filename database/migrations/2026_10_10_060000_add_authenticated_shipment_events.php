<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_shipments', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable();
            $table->unsignedBigInteger('last_event_at_ms')->nullable();
            $table->unsignedInteger('carrier_revision')->default(0);
        });
        Schema::table('store_order_status_changes', function (Blueprint $table) {
            $table->string('source', 40)->nullable();
        });
        Schema::create('store_shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64)->unique();
            $table->integer('carrier_state');
            $table->unsignedBigInteger('carrier_time_ms')->nullable();
            $table->string('source', 40);
            $table->boolean('applied');
            $table->string('ignored_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'store_shipment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_shipment_events');
        Schema::table('store_order_status_changes', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('store_shipments', fn (Blueprint $table) => $table->dropColumn(['webhook_secret', 'last_event_at_ms', 'carrier_revision']));
    }
};
