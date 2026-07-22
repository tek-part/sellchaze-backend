<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4E (Task 1): store-owned dynamic pages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('status', 20)->default('draft');   // draft | published | scheduled
            $table->string('template', 40)->default('page');   // page | landing
            $table->string('locale', 5)->default('en');
            $table->json('seo')->nullable();                    // { title, description, og_image, robots }
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'slug', 'locale']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pages');
    }
};
