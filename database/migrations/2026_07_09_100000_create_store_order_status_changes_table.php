<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6E: order status/history audit trail (mirrors theme_status_changes).
 * Store-scoped; actor is the platform user who made the change (null when the
 * change was customer-initiated, e.g. a self-service cancel). `notes` carries
 * the merchant's internal note for the change — merchant/admin visible only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_order_id', 'id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_order_status_changes');
    }
};
