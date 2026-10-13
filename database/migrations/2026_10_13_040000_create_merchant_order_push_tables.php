<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('auth_session_id');
            $table->foreign('auth_session_id')->references('id')->on('auth_sessions')->restrictOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('subscription');
            $table->char('vapid_fingerprint', 64);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['store_id', 'user_id', 'enabled']);
        });
        Schema::create('merchant_order_push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('merchant_push_subscriptions')->restrictOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->restrictOnDelete();
            $table->unsignedInteger('subscription_version');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'store_order_id'], 'merchant_push_order_once');
            $table->index(['status', 'next_attempt_at'], 'merchant_push_due');
        });
    }

    public function down(): void
    {
        if (DB::table('merchant_push_subscriptions')->exists() || DB::table('merchant_order_push_deliveries')->exists()) {
            throw new RuntimeException('Merchant push subscriptions and delivery history must be preserved.');
        }
        Schema::dropIfExists('merchant_order_push_deliveries');
        Schema::dropIfExists('merchant_push_subscriptions');
    }
};
