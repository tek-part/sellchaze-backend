<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->json('personalization_fields')->nullable());
        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('personalization')->nullable();
            $table->string('personalization_key', 64)->default('');
            $table->dropUnique('cart_product_variant_unique');
            $table->unique(['cart_id', 'store_product_id', 'variant_id', 'personalization_key'], 'cart_personalized_line_unique');
        });
        Schema::table('store_order_items', fn (Blueprint $table) => $table->json('personalization')->nullable());
        Schema::create('product_personalization_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('store_id')->index();
            // Historical files survive product removal once used in an order.
            $table->unsignedBigInteger('product_id');
            $table->string('field_key', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('path');
            $table->string('filename');
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('products')->whereNotNull('personalization_fields')->exists() || DB::table('cart_items')->where('personalization_key', '!=', '')->exists() || DB::table('product_personalization_uploads')->exists() || DB::table('store_order_items')->whereNotNull('personalization')->exists()) {
            throw new RuntimeException('Preserve personalization data before rolling back this migration.');
        }
        Schema::dropIfExists('product_personalization_uploads');
        Schema::table('store_order_items', fn (Blueprint $table) => $table->dropColumn('personalization'));
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_personalized_line_unique');
            $table->dropColumn(['personalization', 'personalization_key']);
            $table->unique(['cart_id', 'store_product_id', 'variant_id'], 'cart_product_variant_unique');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('personalization_fields'));
    }
};
