<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audited bulk price changes: every applied change keeps the previous prices so it can be reverted at once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('status', 12)->default('applied');
            $table->json('scope');
            $table->json('operation');
            $table->unsignedInteger('product_count')->default(0);
            $table->unsignedInteger('variant_count')->default(0);
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->json('revert_result')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['store_id', 'id']);
        });
        Schema::create('store_price_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_price_change_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('store_product_variants')->cascadeOnDelete();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->decimal('old_compare_price', 12, 2)->nullable();
            $table->decimal('new_compare_price', 12, 2)->nullable();
            $table->index(['store_price_change_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_price_change_items');
        Schema::dropIfExists('store_price_changes');
    }
};
