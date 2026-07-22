<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('type', 32)->default('manual_payment')->after('customer_user_id');
            $table->unsignedBigInteger('quotation_id')->nullable()->after('type');
            $table->foreign('quotation_id')->references('id')->on('order_quotations')->nullOnDelete();
            $table->index(['supplier_user_id', 'customer_user_id']);
            $table->index('type');
        });

        // Make transfer_method nullable for auto-generated order_charge rows.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transfer_method')->nullable()->change();
            $table->longText('orders')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['quotation_id']);
            $table->dropIndex(['supplier_user_id', 'customer_user_id']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'quotation_id']);
        });
    }
};
