<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('invite_code', 16)->nullable()->unique()->after('invitation_token');
            $table->string('invited_role', 32)->nullable()->after('invite_code');
            $table->string('status', 32)->default('pending')->after('invited_role');
            $table->timestamp('expires_at')->nullable()->after('status');
        });

        if (Schema::hasTable('invitations')) {
            try {
                Schema::table('invitations', function (Blueprint $table) {
                    $table->dropUnique(['email']);
                });
            } catch (\Throwable $e) {
                // Index name may differ per driver; ignore if already dropped
            }
            Schema::table('invitations', function (Blueprint $table) {
                $table->index(['sender_user_id', 'email']);
            });

            DB::table('invitations')->whereNotNull('registered_at')->update(['status' => 'accepted']);
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->where('name', 'Customer')->update(['name' => 'Merchant']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles')) {
            DB::table('roles')->where('name', 'Merchant')->update(['name' => 'Customer']);
        }

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['sender_user_id', 'email']);
            $table->dropColumn(['invite_code', 'invited_role', 'status', 'expires_at']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
