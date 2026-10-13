<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->text('creation_receipt')->nullable();
            $table->timestamp('receipt_expires_at')->nullable()->index();
            $table->string('description', 200)->nullable();
            $table->json('permissions');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'user_id']);
        });
        Schema::table('store_orders', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        if (DB::table('store_api_keys')->exists() || DB::table('store_orders')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Refusing to discard populated API keys or archived orders.');
        }
        Schema::table('store_orders', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::dropIfExists('store_api_keys');
    }
};
