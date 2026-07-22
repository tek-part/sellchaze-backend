<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id'], 'warehouse_inventories_wh_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inventories');
    }
};
