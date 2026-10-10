<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('digital_type', 16)->default('physical');
            $table->text('digital_url')->nullable();
        });
        Schema::table('store_order_items', fn (Blueprint $table) => $table->text('digital_delivery')->nullable());
        Schema::create('product_digital_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->text('value');
            $table->string('digest', 64);
            $table->unsignedBigInteger('store_order_item_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['store_id', 'product_id', 'digest'], 'product_digital_code_unique');
            $table->index(['store_id', 'product_id', 'store_order_item_id'], 'product_digital_code_available');
        });
    }

    public function down(): void
    {
        if (DB::table('products')->where('digital_type', '!=', 'physical')->exists() || DB::table('product_digital_codes')->exists() || DB::table('store_order_items')->whereNotNull('digital_delivery')->exists()) {
            throw new RuntimeException('Preserve digital product and order data before rolling back.');
        }
        Schema::dropIfExists('product_digital_codes');
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn('digital_delivery'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['digital_type', 'digital_url']));
    }
};
