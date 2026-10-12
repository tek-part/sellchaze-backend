<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('tracking_configuration')->nullable();
            $table->text('tracking_credentials')->nullable();
        });
        Schema::create('store_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('event_key', 64);
            $table->string('event_name', 32);
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('payload');
            $table->timestamp('occurred_at');
            $table->unique(['store_id', 'event_key'], 'tracking_store_event_unique');
        });
        Schema::create('store_tracking_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_tracking_event_id')->constrained()->cascadeOnDelete();
            $table->uuid('destination_id');
            $table->uuid('generation');
            $table->string('provider', 16);
            $table->string('pixel_id', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('lease')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['store_tracking_event_id', 'destination_id'], 'tracking_event_destination_unique');
            $table->index(['status', 'available_at'], 'tracking_delivery_due');
        });
    }

    public function down(): void
    {
        if (DB::table('stores')->whereNotNull('tracking_configuration')->orWhereNotNull('tracking_credentials')->exists()
            || DB::table('store_tracking_events')->exists() || DB::table('store_tracking_deliveries')->exists()) {
            throw new RuntimeException('Preserve tracking settings, credentials and delivery history before rollback.');
        }
        Schema::dropIfExists('store_tracking_deliveries');
        Schema::dropIfExists('store_tracking_events');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['tracking_configuration', 'tracking_credentials']));
    }
};
