<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('shipping_address')->nullable()->after('notes');
            $table->string('shipping_type', 50)->default('customer_direct')->after('shipping_address'); // customer_direct, warehouse
            $table->string('payment_method', 50)->nullable()->after('shipping_type'); // tab, paypal, stripe, cod, bank_transfer
            $table->string('payment_type', 50)->default('full')->after('payment_method'); // full, partial_cod
            $table->decimal('paid_amount', 12, 2)->nullable()->after('payment_type');
            $table->string('delivery_path', 50)->default('customer_direct')->after('paid_amount'); // customer_direct, pickup_point
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_address', 'shipping_type', 'payment_method',
                'payment_type', 'paid_amount', 'delivery_path'
            ]);
        });
    }
};
