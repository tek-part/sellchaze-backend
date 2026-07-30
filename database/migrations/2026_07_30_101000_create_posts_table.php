<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The community feed / wall. One row per post by a registered supplier/merchant, visible to all
 * registered users. `type` distinguishes the five allowed post kinds; `body` holds the rich-text
 * HTML; `product_id` optionally attaches one of the author's own products (a post can be plain text
 * OR reference a product); `sector_id` drives the "your sector first" feed ordering/filtering;
 * `meta` carries structured RFQ fields when type = rfq. Engagement counts are denormalised for cheap
 * feed rendering and kept in sync by the like/comment/share endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->enum('type', ['new_product', 'ad_offer', 'rfq', 'update_news', 'question'])->default('update_news');
            $table->longText('body')->nullable();          // rich HTML from the composer
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('attachments')->nullable();        // [{url, ...}] optional images
            $table->json('meta')->nullable();               // rfq: {quantity, unit, budget, deadline, ...}
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->enum('status', ['published', 'hidden'])->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['sector_id', 'published_at']);
            $table->index(['user_id', 'published_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
