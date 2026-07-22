<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_category_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'is_active']);
            $table->index(['store_id', 'is_featured']);
            $table->index(['store_id', 'store_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
