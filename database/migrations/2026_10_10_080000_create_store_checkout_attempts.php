<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->string('owner_hash', 64);
            $table->string('request_hash', 64);
            $table->uuid('lease');
            $table->timestamp('claimed_at');
            // Keep the identity after order deletion; never permit this key to create again.
            $table->unsignedBigInteger('store_order_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_checkout_attempts');
    }
};
