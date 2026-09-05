<?php

namespace App\Services\PageBuilder;

use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\StorePageRevision;
use App\Models\StorePageSection;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\SectionRegistry;
use App\Services\Themes\ThemeResolver;
use App\Support\Localization\LocaleContext;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Page CRUD + schema-driven section composition + revisions + publish workflow.
 * Section layouts are always validated against the active theme's sections_schema
 * (Task 9), so a page can never persist a section type the theme cannot render.
 */
class StorePageService
{
    public function __construct(
        private readonly SectionRegistry $sections,
        private readonly ThemeResolver $resolver,
        private readonly StorefrontPageCache $cache,
    ) {}

    /** Templates that are singletons per (store, locale) and never served by slug. */
    public const TEMPLATE_PAGES = ['home'];

    public function create(Store $store, array $data): StorePage
    {
        $locale = $data['locale'] ?? LocaleContext::storeDefault($store);
        if (in_array($data['template'] ?? null, self::TEMPLATE_PAGES, true)) {
            // A template page is a singleton: creating it again yields the existing row.
            return $this->ensureTemplatePage($store, $data['template'], $locale)[0];
        }

        $page = new StorePage;
        $page->store_id = $store->id;
        $page->title = $data['title'];
        $page->slug = $this->uniqueSlug($store, $data['slug'] ?? $data['title'], $locale);
        $page->status = $data['status'] ?? 'draft';
        $page->template = $data['template'] ?? 'page';
        $page->locale = $locale;
        $page->seo = $data['seo'] ?? null;
        $page->publish_at = $data['publish_at'] ?? null;
        $page->published_at = ($page->status === 'published') ? now() : null;
        $page->published_slug = ($page->status === 'published') ? $page->slug : null;
        $page->save();

        $this->flush($store->id);

        return $page;
    }

    public function update(StorePage $page, array $data, ?int $actorId = null): StorePage
    {
        $this->saveRevision($page, $actorId); // snapshot the current state before editing

        $isTemplatePage = in_array($page->template, self::TEMPLATE_PAGES, true);
        if (! $isTemplatePage && in_array($data['template'] ?? null, self::TEMPLATE_PAGES, true)) {
            throw ValidationException::withMessages(['template' => ["A page cannot be turned into the '{$data['template']}' template; use the template endpoint."]]);
        }
        if ($isTemplatePage) {
            unset($data['template'], $data['slug'], $data['locale']); // pinned: singleton per (store, locale)
        }

        foreach (['title', 'template', 'seo'] as $key) {
            if (array_key_exists($key, $data)) {
                $page->{$key} = $data[$key];
            }
        }
        if (! empty($data['locale']) && $data['locale'] !== $page->locale) {
            // Moving a page between locales re-checks slug uniqueness within the new locale.
            $page->locale = $data['locale'];
            $page->slug = $this->uniqueSlug($page->store, $data['slug'] ?? $page->slug, $page->locale, $page->id);
        }
        if (! empty($data['slug'])) {
            $page->slug = $this->uniqueSlug($page->store, $data['slug'], $page->locale, $page->id);
        }
        if (array_key_exists('publish_at', $data)) {
            $page->publish_at = $data['publish_at'];
        }
        $page->save();

        return $page;
    }

    /**
     * The singleton template page (e.g. `home`) for a store + locale, created on first
     * access: title-cased, draft, slug = template, seeded from the active theme's
     * manifest `templates.<template>` (section defaults + the template's own settings).
     *
     * @return array{0: StorePage, 1: bool} [page, created]
     */
    public function ensureTemplatePage(Store $store, string $template, ?string $locale = null): array
    {
        if (! in_array($template, self::TEMPLATE_PAGES, true)) {
            throw new \InvalidArgumentException("Unknown template page '{$template}'.");
        }
        $locale ??= LocaleContext::storeDefault($store);

        $existing = StorePage::query()->withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)->where('template', $template)->where('locale', $locale)
            ->orderBy('id')->first();
        if ($existing) {
            return [$existing, false];
        }

        $theme = $this->resolver->resolve($store, $locale) ?? [];
        $seed = $this->sections->resolveSections(
            $theme['sections_schema'] ?? [],
            $theme['templates'][$template]['sections'] ?? [],
        );

        $page = DB::transaction(function () use ($store, $template, $locale, $seed) {
            $page = new StorePage;
            $page->store_id = $store->id;
            $page->title = ucfirst($template);
            $page->slug = $template;
            $page->status = 'draft';
            $page->template = $template;
            $page->locale = $locale;
            $page->save();

            foreach ($seed as $position => $section) {
                StorePageSection::create([
                    'store_page_id' => $page->id,
                    'store_id' => $store->id,
                    'type' => $section['type'],
                    'settings' => $section['settings'],
                    'position' => $position,
                    'is_visible' => true,
                ]);
            }

            return $page;
        });

        return [$page->refresh(), true];
    }

    /**
     * Replace a page's sections from an ordered list (add/reorder/duplicate/remove/edit
     * are all expressed by the incoming layout). Unsupported section types are dropped
     * (graceful degradation) — validated against the active theme sections_schema.
     *
     * @param  array<int,array{type:string,settings?:array,reusable_section_id?:int,is_visible?:bool}>  $sections
     */
    public function syncSections(StorePage $page, array $sections, ?int $actorId = null): StorePage
    {
        $this->saveRevision($page, $actorId);

        $schema = ($this->resolver->resolve($page->store))['sections_schema'] ?? [];

        DB::transaction(function () use ($page, $sections, $schema) {
            $page->sections()->delete();
            $position = 0;
            foreach ($sections as $section) {
                $type = $section['type'] ?? null;
                if ($type === null || ! $this->sections->isValidType($type, $schema)) {
                    continue; // drop unsupported types
                }
                StorePageSection::create([
                    'store_page_id' => $page->id,
                    'store_id' => $page->store_id,
                    'type' => $type,
                    'settings' => $this->sections->sanitizeSettings($schema[$type]['settings'] ?? [], $section['settings'] ?? []),
                    'reusable_section_id' => $section['reusable_section_id'] ?? null,
                    'position' => $position++,
                    'is_visible' => $section['is_visible'] ?? true,
                ]);
            }
        });
        $this->flush($page->store_id); // draft previews + scheduled-but-unpublished pages read live rows

        return $page->refresh();
    }

    public function publish(StorePage $page): StorePage
    {
        DB::transaction(function () use ($page): void {
            $snapshot = $this->snapshot($page);
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $version = (int) DB::table('store_page_publications')->where('store_page_id', $page->id)->max('version') + 1;
            DB::table('store_page_publications')->insert([
                'store_page_id' => $page->id,
                'store_id' => $page->store_id,
                'version' => $version,
                'snapshot' => $json,
                'checksum' => hash('sha256', $json),
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $page->status = 'published';
            $page->published_at = now();
            $page->published_slug = $page->slug;
            $page->save();
        });
        $this->flush($page->store_id);

        return $page;
    }

    public function snapshot(StorePage $page): array
    {
        $page->load('sections.reusable');

        return [
            'page' => $page->only(['title', 'slug', 'template', 'locale', 'seo']),
            'sections' => $page->sections->map(function (StorePageSection $section) {
                $reusable = $section->reusable_section_id ? $section->reusable : null;

                return [
                    'type' => $reusable?->type ?? $section->type,
                    'settings' => $reusable ? array_merge($reusable->settings ?? [], $section->settings ?? []) : ($section->settings ?? []),
                    'reusable_section_id' => $section->reusable_section_id,
                    'is_visible' => (bool) $section->is_visible,
                    'position' => $section->position,
                ];
            })->values()->all(),
        ];
    }

    public function hasUnpublishedChanges(StorePage $page): bool
    {
        $json = json_encode($this->snapshot($page), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $published = DB::table('store_page_publications')->where('store_page_id', $page->id)->orderByDesc('version')->value('checksum');

        return $published === null || ! hash_equals((string) $published, hash('sha256', $json));
    }

    public function schedule(StorePage $page, \DateTimeInterface $publishAt): StorePage
    {
        $page->status = 'scheduled';
        $page->publish_at = $publishAt;
        $page->save();
        $this->flush($page->store_id);

        return $page;
    }

    public function unpublish(StorePage $page): StorePage
    {
        $page->status = 'draft';
        $page->published_at = null;
        $page->published_slug = null;
        $page->save();
        $this->flush($page->store_id);

        return $page;
    }

    public function delete(StorePage $page): void
    {
        $storeId = $page->store_id;
        $page->delete();
        $this->flush($storeId);
    }

    public function saveRevision(StorePage $page, ?int $actorId = null): StorePageRevision
    {
        $next = (int) StorePageRevision::query()->where('store_page_id', $page->id)->max('revision_number') + 1;

        return StorePageRevision::create([
            'store_page_id' => $page->id,
            'store_id' => $page->store_id,
            'revision_number' => $next,
            'created_by' => $actorId,
            'snapshot' => [
                'page' => $page->only(['title', 'slug', 'status', 'template', 'locale', 'seo']),
                'sections' => $page->sections->map(fn (StorePageSection $s) => [
                    'type' => $s->type, 'settings' => $s->settings, 'reusable_section_id' => $s->reusable_section_id,
                    'is_visible' => (bool) $s->is_visible,
                ])->all(),
            ],
        ]);
    }

    public function restoreRevision(StorePage $page, StorePageRevision $revision, ?int $actorId = null): StorePage
    {
        $this->saveRevision($page, $actorId); // snapshot current before restoring
        $snap = $revision->snapshot;

        $page->fill($snap['page'] ?? []);
        $page->save();

        DB::transaction(function () use ($page, $snap) {
            $page->sections()->delete();
            $position = 0;
            foreach (($snap['sections'] ?? []) as $section) {
                StorePageSection::create([
                    'store_page_id' => $page->id,
                    'store_id' => $page->store_id,
                    'type' => $section['type'],
                    'settings' => $section['settings'] ?? [],
                    'reusable_section_id' => $section['reusable_section_id'] ?? null,
                    'position' => $position++,
                    'is_visible' => $section['is_visible'] ?? true,
                ]);
            }
        });

        return $page->refresh();
    }

    private function uniqueSlug(Store $store, string $base, string $locale, ?int $ignoreId = null): string
    {
        return Slug::unique($base, function (string $slug) use ($store, $locale, $ignoreId) {
            return StorePage::query()->withoutGlobalScope(StoreScope::class)
                ->where('store_id', $store->id)->where('locale', $locale)->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
        }, 'page');
    }

    private function flush(int $storeId): void
    {
        $this->cache->flushStore($storeId);
    }
}
