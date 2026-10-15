<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_catalog_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('operation', 20);
            $table->char('payload_hash', 64);
            $table->longText('source_payload')->nullable();
            $table->json('result');
            $table->timestamps();
            $table->unique(['store_id', 'idempotency_key']);
        });
        Schema::create('store_product_categories', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['product_id', 'category_id']);
            $table->index(['store_id', 'category_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('store_catalog_operations')->exists() || DB::table('store_product_categories')->exists()) {
            throw new RuntimeException('Catalog transfer history and category memberships must be preserved before rollback.');
        }
        Schema::dropIfExists('store_product_categories');
        Schema::dropIfExists('store_catalog_operations');
    }
};
