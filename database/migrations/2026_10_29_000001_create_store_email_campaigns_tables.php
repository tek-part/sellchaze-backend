<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant e-mail marketing campaigns (Marketing > Email campaigns). A campaign is a list of content
 * blocks and an audience definition; when it starts sending, its eligible recipients (active e-mail
 * newsletter subscribers only) are snapshotted into store_email_campaign_recipients, one row per
 * address, and each row is sent at most once. The recipient token drives unsubscribe and tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('subject', 200)->nullable();
            $table->string('preheader', 200)->nullable();
            $table->string('from_name', 100)->nullable();
            $table->string('reply_to', 255)->nullable();
            $table->json('content')->nullable();
            $table->string('locale', 5)->default('en');
            $table->json('audience')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            // Every URL the sent content links to, frozen when sending starts: the click redirect only
            // ever forwards to one of these.
            $table->json('link_urls')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'sec_store_status_idx');
            $table->index(['status', 'scheduled_at'], 'sec_status_scheduled_idx');
        });

        Schema::create('store_email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('store_email_campaigns')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('customer_key', 255)->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('token', 64)->unique('secr_token_unique');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email'], 'secr_campaign_email_unique');
            $table->index(['campaign_id', 'status'], 'secr_campaign_status_idx');
            $table->index(['store_id', 'email', 'status'], 'secr_store_email_status_idx');
            $table->index(['store_id', 'dispatched_at'], 'secr_store_dispatched_idx');
            $table->index(['dispatched_at'], 'secr_dispatched_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('store_email_campaign_recipients')->whereIn('status', ['unsubscribed', 'bounced'])->exists()) {
            throw new RuntimeException('E-mail campaign unsubscribes and bounces are suppression records; export them before rolling back.');
        }
        Schema::dropIfExists('store_email_campaign_recipients');
        Schema::dropIfExists('store_email_campaigns');
    }
};
