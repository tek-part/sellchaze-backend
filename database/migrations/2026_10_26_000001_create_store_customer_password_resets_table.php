<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time password reset links for store customers. Store-scoped like the
 * customers themselves; only the SHA-256 hash of the emailed token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customer_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'store_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customer_password_resets');
    }
};
