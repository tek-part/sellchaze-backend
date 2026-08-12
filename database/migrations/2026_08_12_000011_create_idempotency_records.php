<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 255);
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->string('state', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('content_type', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key'], 'idempotency_actor_scope_key_unique');
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};

