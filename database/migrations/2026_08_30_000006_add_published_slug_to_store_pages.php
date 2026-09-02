<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            $table->string('published_slug')->nullable()->after('slug');
            $table->index(['store_id', 'published_slug']);
        });

        DB::table('store_pages')->whereIn('status', ['published', 'scheduled'])->update(['published_slug' => DB::raw('slug')]);

        DB::table('store_pages')->where('status', 'published')->orderBy('id')->eachById(function ($page) {
            if (DB::table('store_page_publications')->where('store_page_id', $page->id)->exists()) return;
            $sections = DB::table('store_page_sections')->where('store_page_id', $page->id)->orderBy('position')->get()->map(fn ($section) => [
                'type' => $section->type,
                'settings' => json_decode($section->settings ?: '[]', true) ?: [],
                'reusable_section_id' => $section->reusable_section_id,
                'is_visible' => (bool) $section->is_visible,
                'position' => (int) $section->position,
            ])->all();
            $snapshot = ['page' => [
                'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template,
                'locale' => $page->locale, 'seo' => json_decode($page->seo ?: '[]', true) ?: [],
            ], 'sections' => $sections];
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('store_page_publications')->insert([
                'store_page_id' => $page->id, 'store_id' => $page->store_id, 'version' => 1,
                'snapshot' => $json, 'checksum' => hash('sha256', $json),
                'published_at' => $page->published_at ?? now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'published_slug']);
            $table->dropColumn('published_slug');
        });
    }
};
