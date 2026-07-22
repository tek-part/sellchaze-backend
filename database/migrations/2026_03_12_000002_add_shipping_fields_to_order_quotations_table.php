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
        Schema::table('order_quotations', function (Blueprint $table) {
            $table->boolean('price_includes_shipping')->default(true)->after('price');
            $table->string('currency', 10)->default('EGP')->after('price_includes_shipping');
            $table->string('shipping_company')->nullable()->after('currency');
            $table->string('tracking_number')->nullable()->after('shipping_company');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_quotations', function (Blueprint $table) {
            $table->dropColumn([
                'price_includes_shipping', 'currency', 'shipping_company',
                'tracking_number', 'shipped_at'
            ]);
        });
    }
};
