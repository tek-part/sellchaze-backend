<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: opaque bearer tokens for store-customer sessions. The plaintext
 * token is returned once at login/register; only its SHA-256 hash is stored.
 * Store-scoped so a token can never authenticate against another store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'store_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customer_tokens');
    }
};
