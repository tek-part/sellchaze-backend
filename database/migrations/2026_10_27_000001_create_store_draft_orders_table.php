<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shopify-style draft orders: a merchant-built quote that becomes a real order exactly once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_draft_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            // Per-store sequence behind the human number (D-1001, D-1002, ...).
            $table->unsignedInteger('sequence');
            $table->string('number', 32);
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('store_customer_id')->nullable();
            $table->json('customer')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('line_items');
            $table->json('applied_discount')->nullable();
            $table->json('shipping_line')->nullable();
            $table->json('totals')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->json('tags')->nullable();
            $table->char('invoice_token_hash', 64)->nullable();
            $table->timestamp('invoice_sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('completed_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->unsignedBigInteger('completed_by_id')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'sequence'], 'store_draft_orders_sequence_unique');
            $table->unique('invoice_token_hash', 'store_draft_orders_invoice_unique');
            $table->index(['store_id', 'status', 'id'], 'store_draft_orders_status_index');
            $table->index(['store_id', 'store_customer_id'], 'store_draft_orders_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_draft_orders');
    }
};
