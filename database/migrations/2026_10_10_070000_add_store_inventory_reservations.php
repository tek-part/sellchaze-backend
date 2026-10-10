<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_inventory')->default(false);
        });
        Schema::table('store_product_variants', function (Blueprint $table) {
            $table->boolean('track_inventory')->default(false);
        });
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->string('inventory_status', 20)->nullable();
        });
        Schema::create('store_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Historical identities survive catalog removal.
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('store_order_item_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 30);
            $table->integer('stock_delta');
            $table->integer('reserved_delta');
            $table->integer('stock_after');
            $table->integer('reserved_after');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_inventory_movements');
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn('inventory_status'));
        Schema::table('store_product_variants', fn (Blueprint $table) => $table->dropColumn('track_inventory'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('track_inventory'));
    }
};
