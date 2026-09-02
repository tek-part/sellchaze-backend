<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('payment_method', 80)->nullable()->after('shipping_address');
            $table->string('payment_status', 30)->default('pending')->after('payment_method');
            $table->string('payment_reference')->nullable()->after('payment_status');
            $table->index(['store_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'payment_status']);
            $table->dropColumn(['payment_method', 'payment_status', 'payment_reference']);
        });
    }
};
