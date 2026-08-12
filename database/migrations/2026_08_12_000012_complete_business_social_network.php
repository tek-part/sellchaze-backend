<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('headline', 240)->nullable();
            $table->text('about')->nullable();
            $table->string('website', 2048)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->json('locations')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('featured_products')->nullable();
            $table->json('certificates')->nullable();
            $table->boolean('is_verified')->default(false)->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('organization_verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderator_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('verified');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'published_at']);
        });

        Schema::create('post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('organization_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('user_safety_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestamps();
            $table->unique(['actor_user_id', 'target_user_id', 'type']);
            $table->index(['actor_user_id', 'type']);
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['reporter_user_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderator_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 32);
            $table->text('notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_verification_events');
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('user_safety_relations');
        Schema::dropIfExists('organization_follows');
        Schema::dropIfExists('post_saves');
        Schema::table('posts', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn([
                'headline', 'about', 'website', 'logo_url', 'cover_url', 'locations', 'capabilities',
                'featured_products', 'certificates', 'is_verified', 'verified_at',
            ]);
        });
    }
};
