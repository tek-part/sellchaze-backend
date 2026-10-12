<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('balance_milli')->default(0);
            $table->timestamps();
        });
        Schema::create('platform_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('platform_wallet_accounts')->restrictOnDelete();
            $table->string('event_key', 140)->unique();
            $table->string('type', 24);
            $table->bigInteger('amount_milli');
            $table->bigInteger('balance_after_milli');
            $table->foreignId('parent_entry_id')->nullable()->constrained('platform_wallet_entries')->restrictOnDelete();
            $table->foreignId('store_order_id')->nullable()->unique()->constrained('store_orders')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['account_id', 'id']);
        });
        Schema::create('platform_referral_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->uuid('code')->unique();
            $table->timestamps();
        });
        Schema::create('platform_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('platform_referral_profiles')->restrictOnDelete();
            $table->foreignId('invitee_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('commission_basis_points');
            $table->timestamp('created_at');
        });
        Schema::create('platform_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('platform_referral_profiles')->restrictOnDelete();
            $table->string('event_key', 140)->unique();
            $table->string('type', 24);
            $table->bigInteger('amount_milli');
            $table->foreignId('wallet_entry_id')->nullable()->unique()->constrained('platform_wallet_entries')->restrictOnDelete();
            $table->foreignId('earning_entry_id')->nullable()->constrained('platform_commission_entries')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['profile_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['platform_commission_entries', 'platform_referrals', 'platform_referral_profiles', 'platform_wallet_entries', 'platform_wallet_accounts'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve wallet/referral history before rollback.');
            }
        }
        foreach (['platform_commission_entries', 'platform_referrals', 'platform_referral_profiles', 'platform_wallet_entries', 'platform_wallet_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
