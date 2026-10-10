<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_carrier_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('carrier', 40);
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('pickup_location_id')->nullable();
            $table->json('pickup_locations')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'carrier']);
        });
        Schema::create('store_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_carrier_connection_id')->constrained()->restrictOnDelete();
            $table->string('carrier', 40);
            $table->string('status', 40)->default('submitting');
            $table->string('business_reference')->unique();
            $table->string('external_id')->nullable();
            $table->string('tracking_number')->nullable();
            $table->integer('carrier_state')->nullable();
            $table->string('carrier_state_label')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('request_snapshot');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique('store_order_id');
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_shipments');
        Schema::dropIfExists('store_carrier_connections');
    }
};
