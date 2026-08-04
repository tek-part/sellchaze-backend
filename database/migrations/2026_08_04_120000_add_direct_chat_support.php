<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the (previously order-scoped) conversations into a generic
 * user-to-user direct messaging system, broadcast over Pusher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'type')) {
                $table->string('type', 20)->default('direct')->after('id');
            }
            if (! Schema::hasColumn('conversations', 'last_message_at')) {
                $table->timestamp('last_message_at')->nullable()->after('order_id');
            }
        });

        // order_id was NOT NULL (order chat); make it nullable for direct chats.
        try {
            \DB::statement('ALTER TABLE conversations MODIFY order_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // ignore if already nullable / driver mismatch
        }

        if (! Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_read_at')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id', 'user_id']);
            });
        }

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('conversations', 'last_message_at')) {
                $table->dropColumn('last_message_at');
            }
        });
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'read_at')) {
                $table->dropColumn('read_at');
            }
        });
    }
};
