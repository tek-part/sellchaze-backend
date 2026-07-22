<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('event_type', 32)->default('user_action')->after('action');
            $table->string('channel', 32)->default('http')->after('event_type');
            $table->string('level', 16)->default('info')->after('channel');
            $table->json('context')->nullable()->after('properties');

            $table->index(['event_type', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['event_type', 'created_at']);
            $table->dropIndex(['channel', 'created_at']);
            $table->dropIndex(['level', 'created_at']);
            $table->dropColumn(['event_type', 'channel', 'level', 'context']);
        });
    }
};
