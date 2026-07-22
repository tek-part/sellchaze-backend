<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pending_approval')) {
                $table->boolean('pending_approval')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'registration_role')) {
                $table->string('registration_role', 32)->nullable()->after('pending_approval');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'registration_role')) {
                $table->dropColumn('registration_role');
            }
            if (Schema::hasColumn('users', 'pending_approval')) {
                $table->dropColumn('pending_approval');
            }
        });
    }
};
