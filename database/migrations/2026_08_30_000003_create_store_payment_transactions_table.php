<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->string('gateway', 80);
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_reference')->nullable();
            $table->string('status', 30)->default('created');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->text('redirect_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status']);
            $table->index(['gateway', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_transactions');
    }
};
