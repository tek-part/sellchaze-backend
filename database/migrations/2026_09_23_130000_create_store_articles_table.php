<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('slug', 180);
            $table->json('draft');
            $table->json('legacy_positions')->nullable();
            $table->json('publication')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->json('scheduled_publication')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'archived', 'publish_at']);
        });
        Schema::create('store_article_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('original_content');
            $table->boolean('was_published');
            $table->json('article_ids');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_article_imports');
        Schema::dropIfExists('store_articles');
    }
};
