<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notify_login_email')) {
                return;
            }
            // Do not use after('registration_role'): that column is added in a later migration (2026_04_02_160000).
            $table->boolean('notify_login_email')->default(false)->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_login_email');
        });
    }
};
