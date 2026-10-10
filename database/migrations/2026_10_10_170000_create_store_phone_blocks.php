<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_phone_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 50);
            $table->string('phone_normalized', 32);
            $table->string('phone_country', 2);
            $table->text('note')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['store_id', 'phone_normalized']);
            $table->index(['store_id', 'active', 'id']);
        });
        Schema::create('store_phone_block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_phone_block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->text('note')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['store_id', 'store_phone_block_id', 'id'], 'phone_block_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_phone_block_events');
        Schema::dropIfExists('store_phone_blocks');
    }
};
