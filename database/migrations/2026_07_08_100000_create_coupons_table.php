<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6D: store-scoped discount coupons. Code is unique per store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('type', 12); // fixed | percentage
            $table->decimal('value', 12, 2);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->decimal('minimum_order_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
