<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('phone_otp_configuration')->nullable();
            $table->text('phone_otp_credentials')->nullable();
        });
        Schema::table('store_phone_blocks', function (Blueprint $table) {
            $table->string('scope', 12)->default('checkout');
            $table->dropUnique(['store_id', 'phone_normalized']);
            $table->unique(['store_id', 'scope', 'phone_normalized'], 'store_phone_scope_unique');
        });
        Schema::create('store_phone_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('phone_normalized', 32);
            $table->string('token_hash', 64);
            $table->string('code_hash', 64)->nullable();
            $table->string('ip_hash', 64);
            $table->uuid('generation');
            $table->string('state', 16)->default('dispatching');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('proof_expires_at')->nullable();
            $table->foreignId('store_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'token_hash'], 'store_phone_challenge_token');
            $table->index(['store_id', 'phone_normalized', 'created_at'], 'store_phone_send_window');
            $table->index(['store_id', 'ip_hash', 'created_at'], 'store_phone_ip_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_phone_challenges');
        // OTP-specific records cannot be interpreted as checkout blocks on rollback.
        $ids = DB::table('store_phone_blocks')->where('scope', 'otp')->pluck('id');
        DB::table('store_phone_block_events')->whereIn('store_phone_block_id', $ids)->delete();
        DB::table('store_phone_blocks')->where('scope', 'otp')->delete();
        Schema::table('store_phone_blocks', function (Blueprint $table) {
            $table->dropUnique('store_phone_scope_unique');
            $table->unique(['store_id', 'phone_normalized']);
            $table->dropColumn('scope');
        });
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn(['phone_otp_configuration', 'phone_otp_credentials']));
    }
};
