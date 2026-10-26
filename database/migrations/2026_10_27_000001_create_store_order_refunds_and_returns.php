<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds are bookkeeping records (no gateway call): how much was returned to the customer, for
 * which lines, by which method, and whether stock went back on the shelf. Returns are the
 * customer/merchant workflow that may end in a refund, an exchange or store credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->decimal('refunded_total', 12, 2)->default(0)->after('grand_total');
        });

        Schema::create('store_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('method', 30);
            $table->boolean('restocked')->default(false);
            // [{store_order_item_id, quantity, amount, restocked_quantity}]
            $table->json('items');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'store_order_id', 'id']);
        });

        Schema::create('store_order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->string('status', 20)->default('requested');
            $table->string('reason', 40);
            $table->text('customer_note')->nullable();
            $table->text('merchant_note')->nullable();
            // [{store_order_item_id, quantity}]
            $table->json('items');
            $table->string('resolution', 20)->nullable();
            $table->foreignId('store_order_refund_id')->nullable()->constrained('store_order_refunds')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status', 'id']);
            $table->index(['store_id', 'store_order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_order_returns');
        Schema::dropIfExists('store_order_refunds');
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropColumn('refunded_total'));
    }
};
