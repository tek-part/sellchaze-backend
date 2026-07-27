<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catalog unification (per-owner).
 *
 * The platform historically carried TWO catalogs: a shared B2B taxonomy
 * (categories with store_id = NULL, user_id absent) that every owner's products
 * pointed at, and an empty per-store storefront catalog. We collapse to ONE
 * per-owner catalog keyed on the product owner (products.user_id, which already
 * scopes the B2B products page).
 *
 * Products keep store_id = NULL (so orders / inventory / bundles that read them
 * in the store-less admin context are untouched). Category ownership is added so
 * each owner edits their own categories:
 *
 *   1. add categories.user_id
 *   2. duplicate the shared taxonomy once per product-owner (user_id = owner)
 *   3. re-point each owner's products.category_id at that owner's copy
 *   4. backfill product slugs (unique per owner) so storefronts can resolve them
 *   5. drop the now-orphaned shared source categories
 *
 * Idempotent: re-running reuses an owner's existing copies and only fills gaps.
 * The (store_id, slug) unique index tolerates duplicate slugs across owners
 * because store_id is NULL for every row (MySQL treats NULLs as distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'user_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('store_id')->index();
            });
        }

        DB::transaction(function () {
            $ownerIds = DB::table('products')
                ->whereNull('store_id')
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id')
                ->all();

            // The shared B2B taxonomy: store-less, owner-less categories.
            $sources = DB::table('categories')
                ->whereNull('store_id')
                ->whereNull('user_id')
                ->orderBy('id')
                ->get();

            foreach ($ownerIds as $ownerId) {
                $map = $this->duplicateCategoriesForOwner($sources, (int) $ownerId);

                foreach ($map as $sourceId => $copyId) {
                    DB::table('products')
                        ->whereNull('store_id')
                        ->where('user_id', $ownerId)
                        ->where('category_id', $sourceId)
                        ->update(['category_id' => $copyId]);
                }

                $this->backfillProductSlugs((int) $ownerId);
            }

            // Drop the shared sources now that every product points at an owned copy.
            foreach ($sources as $src) {
                $stillReferenced = DB::table('products')->where('category_id', $src->id)->exists()
                    || DB::table('categories')->where('parent_id', $src->id)->exists();

                if (! $stillReferenced) {
                    DB::table('categories')->where('id', $src->id)->delete();
                }
            }
        });
    }

    /**
     * Ensure the given owner has a copy of every source category. Returns
     * [source_category_id => owner_copy_id]. Idempotent: an existing copy
     * (matched by owner + English name or slug) is reused, never re-created.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $sources
     * @return array<int, int>
     */
    private function duplicateCategoriesForOwner($sources, int $ownerId): array
    {
        $map = [];
        $now = now();

        foreach ($sources as $src) {
            $slug = $src->slug ?: Str::slug($src->name_en ?: $src->name ?: ('category-'.$src->id));
            if ($slug === '') {
                $slug = 'category-'.$src->id;
            }

            $existing = DB::table('categories')
                ->whereNull('store_id')
                ->where('user_id', $ownerId)
                ->where(function ($q) use ($src, $slug) {
                    if ($src->name_en) {
                        $q->where('name_en', $src->name_en);
                    }
                    $q->orWhere('slug', $slug);
                })
                ->value('id');

            if ($existing) {
                $map[$src->id] = (int) $existing;

                continue;
            }

            $map[$src->id] = (int) DB::table('categories')->insertGetId([
                'store_id' => null,
                'user_id' => $ownerId,
                'parent_id' => null,
                // NULL, not the source value: wigpleasure_category_id is UNIQUE and
                // these owner copies are not synced from Wigpleasure.
                'wigpleasure_category_id' => null,
                'name' => $src->name,
                'name_en' => $src->name_en,
                'name_ar' => $src->name_ar,
                'slug' => $slug,
                'description' => $src->description,
                'image' => $src->image,
                'icon' => $src->icon,
                'is_active' => $src->is_active,
                'is_featured' => $src->is_featured,
                'position' => $src->position,
                'seo_title' => $src->seo_title,
                'seo_description' => $src->seo_description,
                'translations' => $src->translations,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $map;
    }

    /** Give every slug-less product of an owner a URL-safe slug, unique within that owner. */
    private function backfillProductSlugs(int $ownerId): void
    {
        $used = array_flip(
            DB::table('products')
                ->whereNull('store_id')
                ->where('user_id', $ownerId)
                ->whereNotNull('slug')
                ->where('slug', '!=', '')
                ->pluck('slug')
                ->all()
        );

        $rows = DB::table('products')
            ->whereNull('store_id')
            ->where('user_id', $ownerId)
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->get(['id', 'name', 'sku']);

        foreach ($rows as $row) {
            $base = Str::slug((string) $row->name);
            if ($base === '') {
                $base = Str::slug((string) $row->sku);
            }
            if ($base === '') {
                $base = 'product-'.$row->id;
            }

            $slug = $base;
            $i = 1;
            while (isset($used[$slug])) {
                $slug = $base.'-'.(++$i);
            }
            $used[$slug] = true;

            DB::table('products')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        // Non-reversible data merge (the shared source categories were dropped).
        // Only the schema column is removed; restore data from backup if needed.
        if (Schema::hasColumn('categories', 'user_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
