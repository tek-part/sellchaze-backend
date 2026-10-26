<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards are a tender, not a discount. Runs after store_order_refunds (refund restores link to it). Only a keyed HMAC of the code is stored (with its last
 * four characters for display), so a database copy alone cannot redeem a card. Every balance change
 * is an append-only transaction; an order keeps the amount paid by gift cards next to its total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->char('code_digest', 64);
            $table->char('last4', 4);
            $table->decimal('initial_value', 12, 2);
            $table->decimal('balance', 12, 2);
            $table->char('currency', 3);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 12)->default('manual');
            $table->timestamps();

            $table->unique(['store_id', 'code_digest']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'last4']);
        });

        Schema::create('store_gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained('store_gift_cards')->cascadeOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained('store_orders')->nullOnDelete();
            $table->foreignId('store_order_refund_id')->nullable()->constrained('store_order_refunds')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // redeem:{order}:{card}, cancel:{order}:{card}, refund:{refund}:{card}; null for issue/adjust.
            // The unique key makes every order-driven balance change happen at most once.
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'gift_card_tx_store_key_unique');
            $table->index(['store_id', 'store_order_id']);
            $table->index(['gift_card_id', 'id']);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->decimal('gift_card_total', 12, 2)->default(0)->after('grand_total');
            $table->json('gift_card_details')->nullable()->after('gift_card_total');
        });

        // The part of a refund's amount that went back to gift cards (the rest is money).
        Schema::table('store_order_refunds', function (Blueprint $table) {
            $table->decimal('gift_card_amount', 12, 2)->default(0)->after('shipping_amount');
        });
    }

    public function down(): void
    {
        Schema::table('store_order_refunds', function (Blueprint $table) {
            $table->dropColumn('gift_card_amount');
        });
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['gift_card_total', 'gift_card_details']);
        });
        Schema::dropIfExists('store_gift_card_transactions');
        Schema::dropIfExists('store_gift_cards');
    }
};
