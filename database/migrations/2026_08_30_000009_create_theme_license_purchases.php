<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_license_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchaser_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('status', 24)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->uuid('idempotency_key')->unique();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'theme_id', 'status'], 'theme_purchase_store_theme_status');
        });

        Schema::create('theme_license_purchase_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_license_purchase_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('provider_event_id')->unique();
            $table->string('event_type', 100);
            $table->string('status', 24)->default('processed');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_license_purchase_events');
        Schema::dropIfExists('theme_license_purchases');
    }
};
