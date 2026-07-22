<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'wigpleasure_order_id')) {
                $table->unsignedBigInteger('wigpleasure_order_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('orders', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('orders', 'customer_email')) {
                $table->string('customer_email')->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('orders', 'customer_phone')) {
                $table->string('customer_phone')->nullable()->after('customer_email');
            }
            if (!Schema::hasColumn('orders', 'shipping_address_json')) {
                $table->json('shipping_address_json')->nullable()->after('shipping_address');
            }
            if (!Schema::hasColumn('orders', 'currency')) {
                $table->string('currency', 10)->default('AED')->after('paid_amount');
            }
            if (!Schema::hasColumn('orders', 'payment_transaction_id')) {
                $table->string('payment_transaction_id')->nullable()->after('currency');
            }
            if (!Schema::hasColumn('orders', 'wigpleasure_products')) {
                $table->longText('wigpleasure_products')->nullable()->after('attributes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'wigpleasure_order_id', 'customer_name', 'customer_email',
                'customer_phone', 'shipping_address_json', 'currency',
                'payment_transaction_id',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('orders', 'wigpleasure_products')) {
                $table->dropColumn('wigpleasure_products');
            }
        });
    }
};
