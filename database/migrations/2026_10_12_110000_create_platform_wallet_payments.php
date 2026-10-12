<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_wallet_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->unsignedInteger('amount_milli');
            $table->string('currency', 3)->default('USD');
            $table->string('status', 16)->default('creating');
            $table->boolean('test_mode');
            $table->text('credentials');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('invoice_key', 100)->nullable();
            $table->text('redirect_url')->nullable();
            $table->foreignId('wallet_entry_id')->nullable()->constrained('platform_wallet_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->unique(['test_mode', 'invoice_id']);
            $table->unique(['test_mode', 'invoice_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('platform_wallet_payments')->exists()) {
            throw new RuntimeException('Payment attempts must be retained for provider reconciliation.');
        }
        Schema::dropIfExists('platform_wallet_payments');
    }
};
