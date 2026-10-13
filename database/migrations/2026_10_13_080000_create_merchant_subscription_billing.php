<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('platform_merchant_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->nullable();
            $table->foreignUuid('renewal_of_id')->nullable()->constrained('platform_merchant_subscriptions')->restrictOnDelete();
            $table->foreignId('wallet_entry_id')->unique()->constrained('platform_wallet_entries')->restrictOnDelete();
            $table->string('cycle', 10);
            $table->unsignedInteger('price_milli');
            $table->boolean('initial_auto_renew')->default(false);
            $table->unsignedBigInteger('order_boundary');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->unique('renewal_of_id');
            $table->index(['user_id', 'ends_at']);
        });
        Schema::create('platform_order_fee_exemptions', function (Blueprint $table) {
            $table->foreignId('store_order_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained('platform_merchant_subscriptions')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['platform_order_fee_exemptions', 'platform_merchant_subscriptions', 'platform_subscription_preferences'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to discard populated merchant subscription history or preferences.');
            }
        }
        Schema::dropIfExists('platform_order_fee_exemptions');
        Schema::dropIfExists('platform_merchant_subscriptions');
        Schema::dropIfExists('platform_subscription_preferences');
    }
};
