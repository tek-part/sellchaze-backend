<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->text('url');
            $table->string('description', 200)->nullable();
            $table->string('event_type', 32);
            $table->text('secret');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'enabled', 'event_type']);
            $table->unique(['store_id', 'request_key']);
        });
        Schema::create('store_order_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_id')->constrained('store_order_webhooks')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('webhook_version');
            $table->string('event_key', 100);
            $table->longText('payload')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_id', 'event_key']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        foreach (['store_order_webhook_deliveries', 'store_order_webhooks'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to discard populated merchant webhook data.');
            }
        }
        Schema::dropIfExists('store_order_webhook_deliveries');
        Schema::dropIfExists('store_order_webhooks');
    }
};
