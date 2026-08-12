<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->string('privacy', 16)->default('public');
            $table->json('rules')->nullable();
            $table->unsignedInteger('members_count')->default(1);
            $table->unsignedInteger('posts_count')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->index(['sector_id', 'members_count']);
        });

        Schema::create('community_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->string('status', 16)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['community_group_id', 'user_id'], 'community_group_member_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('object_key', 2048)->nullable();
            $table->string('original_name', 512);
            $table->string('kind', 16);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('status', 24)->default('initiated');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['kind', 'status', 'created_at']);
        });

        Schema::create('media_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('uploaded_chunks')->default(0);
            $table->string('status', 24)->default('initiated');
            $table->timestamp('expires_at');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('media_upload_parts', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_upload_id');
            $table->unsignedInteger('part_number');
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('temporary_path', 2048);
            $table->timestamps();
            $table->foreign('media_upload_id')->references('id')->on('media_uploads')->cascadeOnDelete();
            $table->unique(['media_upload_id', 'part_number'], 'media_upload_part_unique');
        });

        Schema::create('media_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->string('profile', 40);
            $table->string('disk', 32)->default('public');
            $table->string('object_key', 2048);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['media_asset_id', 'profile']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('format', 16)->default('post')->after('type');
            $table->string('lifecycle_status', 24)->default('published')->after('status');
            $table->string('audience', 24)->default('public')->after('lifecycle_status');
            $table->foreignId('community_group_id')->nullable()->after('sector_id')->constrained()->nullOnDelete();
            $table->foreignId('original_post_id')->nullable()->after('community_group_id')->constrained('posts')->nullOnDelete();
            $table->string('cta_type', 32)->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->boolean('comments_enabled')->default(true);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('location_name', 160)->nullable();
            $table->decimal('ranking_score', 12, 4)->default(0);
            $table->index(['format', 'status', 'published_at']);
            $table->index(['community_group_id', 'published_at']);
            $table->index(['lifecycle_status', 'scheduled_at']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('role', 24)->default('attachment');
            $table->string('alt_text', 500)->nullable();
            $table->json('crop')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'media_asset_id']);
            $table->index(['post_id', 'position']);
        });

        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('label', 100);
            $table->unsignedInteger('posts_count')->default(0);
            $table->decimal('trend_score', 12, 4)->default(0);
            $table->timestamps();
            $table->index(['trend_score', 'posts_count']);
        });

        Schema::create('post_hashtag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'hashtag_id']);
        });

        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('like');
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
            $table->index(['post_id', 'type']);
        });

        Schema::create('feed_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->unsignedInteger('value_ms')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['post_id', 'event_type', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_events');
        Schema::dropIfExists('post_reactions');
        Schema::dropIfExists('post_hashtag');
        Schema::dropIfExists('hashtags');
        Schema::dropIfExists('post_media');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_post_id');
            $table->dropConstrainedForeignId('community_group_id');
            $table->dropIndex(['format', 'status', 'published_at']);
            $table->dropIndex(['lifecycle_status', 'scheduled_at']);
            $table->dropColumn(['format', 'lifecycle_status', 'audience', 'cta_type', 'cta_label', 'cta_url', 'comments_enabled', 'scheduled_at', 'location_name', 'ranking_score']);
        });
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('media_upload_parts');
        Schema::dropIfExists('media_uploads');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('community_group_memberships');
        Schema::dropIfExists('community_groups');
    }
};
