<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store editable content for the fixed "system" storefront pages
 * (about, contact, faq, shipping, returns, blog, …). One row per (store, key)
 * holding a typed JSON payload whose shape is defined by a per-key field schema.
 * The storefront merges this over the theme's built-in defaults, so a store only
 * overrides what it wants and unset fields keep the shipped design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_content_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('key', 40);          // about | contact | faq | shipping | returns | blog | ...
            $table->json('data')->nullable();   // typed payload matching the key's field schema
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_content_pages');
    }
};
