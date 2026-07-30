<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global, platform-owned sector taxonomy for the public Supplier Directory.
 *
 * Deliberately SEPARATE from `categories` (which is tenant-scoped per store/owner via ProductScope
 * since the 2026-07-27 catalog unification). Sectors are a fixed, cross-tenant tree — 8 top-level
 * sectors (depth 0) each with sub-specialties (depth 1) — and must never be tenant-scoped. Each row
 * carries bilingual names, an icon, ordering, a per-page SEO block and a 100–150 word intro used by
 * the industry/specialty landing pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('sectors')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('icon')->nullable();          // emoji or icon key rendered in the sector grid
            $table->unsignedTinyInteger('depth')->default(0); // 0 = sector, 1 = specialty
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            // SEO — per landing page. Bilingual title/description + the required 100–150 word intro.
            $table->string('seo_title_en')->nullable();
            $table->string('seo_title_ar')->nullable();
            $table->string('seo_description_en', 500)->nullable();
            $table->string('seo_description_ar', 500)->nullable();
            $table->text('intro_en')->nullable();
            $table->text('intro_ar')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
