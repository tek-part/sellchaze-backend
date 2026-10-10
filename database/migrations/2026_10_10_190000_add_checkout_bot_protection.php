<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('bot_protection_configuration')->nullable();
            $table->text('bot_protection_credentials')->nullable();
        });
        Schema::create('store_bot_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('nonce_hash', 64);
            $table->string('provider_token_hash', 64)->unique();
            $table->string('ip_hash', 64);
            $table->uuid('generation');
            $table->string('state', 16)->default('checking');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'nonce_hash'], 'store_bot_nonce_unique');
            $table->index(['store_id', 'ip_hash', 'created_at'], 'store_bot_ip_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_bot_challenges');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['bot_protection_configuration', 'bot_protection_credentials']));
    }
};
