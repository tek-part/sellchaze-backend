<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: product reviews. Store-scoped; one review per (customer, product).
 * `status` supports moderation (pending|approved|rejected); only approved
 * reviews are shown publicly and counted in the average.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('approved'); // pending | approved | rejected
            $table->timestamps();

            $table->unique(['store_customer_id', 'store_product_id']);
            $table->index(['store_id', 'store_product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
