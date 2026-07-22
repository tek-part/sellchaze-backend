<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_verified')) {
                $table->boolean('is_verified')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('users', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('is_verified');
            }
            if (!Schema::hasColumn('users', 'verified_by_user_id')) {
                $table->unsignedBigInteger('verified_by_user_id')->nullable()->after('verified_at');
                $table->foreign('verified_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->index('is_verified');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['verified_by_user_id']);
            $table->dropIndex(['is_verified']);
            $table->dropColumn(['is_verified', 'verified_at', 'verified_by_user_id']);
        });
    }
};
