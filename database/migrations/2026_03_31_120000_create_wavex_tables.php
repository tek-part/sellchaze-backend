<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wavex_settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->default('https://api.wawp.net');
            $table->text('access_token_encrypted')->nullable();
            $table->string('instance_id', 64)->nullable();
            $table->string('connection_status', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wavex_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('wavex_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('template_id')->nullable()->constrained('wavex_templates')->nullOnDelete();
            $table->text('message_body');
            $table->unsignedInteger('delay_seconds')->default(500);
            $table->string('status', 32)->default('draft'); // draft, running, completed, cancelled, failed
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('wavex_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('wavex_campaigns')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('phone', 32);
            $table->string('jid', 128)->nullable();
            $table->string('display_name')->nullable();
            $table->string('status', 32)->default('pending'); // pending, queued, sent, failed, skipped
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('wavex_inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id', 64)->index();
            $table->string('chat_id', 128)->index();
            $table->string('direction', 16); // in, out
            $table->text('body')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('message_at')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'chat_id', 'created_at']);
        });

        DB::table('wavex_settings')->insert([
            'base_url' => 'https://api.wawp.net',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wavex_inbox_messages');
        Schema::dropIfExists('wavex_campaign_recipients');
        Schema::dropIfExists('wavex_campaigns');
        Schema::dropIfExists('wavex_templates');
        Schema::dropIfExists('wavex_settings');
    }
};
