<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('event_key', 64);
            $table->char('session_key', 64);
            $table->string('kind', 32);
            $table->string('source', 24);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['store_id', 'event_key'], 'sf_events_store_event_unique');
            $table->index(['store_id', 'occurred_at', 'kind'], 'sf_events_store_time_kind');
            $table->index(['store_id', 'session_key', 'kind'], 'sf_events_store_session_kind');
        });
        Schema::table('store_order_items', fn (Blueprint $table) => $table->decimal('unit_cost', 14, 2)->nullable());
        Schema::table('store_orders', fn (Blueprint $table) => $table->char('analytics_session_hash', 64)->nullable());
    }

    public function down(): void
    {
        if (DB::table('storefront_events')->exists() || DB::table('store_order_items')->whereNotNull('unit_cost')->exists() || DB::table('store_orders')->whereNotNull('analytics_session_hash')->exists()) {
            throw new RuntimeException('Preserve recorded events, order costs and session snapshots before rollback.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('analytics_session_hash'));
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::dropIfExists('storefront_events');
    }
};
