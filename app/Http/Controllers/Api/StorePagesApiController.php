<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\StorePageRevision;
use App\Models\ThemeVersion;
use App\Services\PageBuilder\StorePageService;
use App\Services\Storefront\StorefrontUrlGenerator;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeResolver;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Page Builder management for a store. Mounted under /stores/{store}/pages with
 * the store.scope middleware, so every action is owner/admin-only and tenant-safe
 * (StoreScope isolates pages, sections and revisions to this store).
 */
class StorePagesApiController extends Controller
{
    public function __construct(
        private readonly StorePageService $service,
        private readonly ThemeResolver $resolver,
        private readonly ThemePreviewToken $previewToken,
        private readonly StorefrontUrlGenerator $urls,
    ) {}

    /**
     * GET /stores/{store}/pages — custom pages. `?template=page,landing` filters by template;
     * without it the singleton template pages (`home`) are left out so the dashboard's
     * "Pages" list only shows what is reachable at /pages/{slug}.
     */
    public function index(Request $request, Store $store): JsonResponse
    {
        $templates = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('template', '')))));

        $pages = StorePage::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($templates !== [], fn ($q) => $q->whereIn('template', $templates), fn ($q) => $q->whereNotIn('template', StorePageService::TEMPLATE_PAGES))
            ->orderByDesc('id')->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data' => $pages->getCollection()->map(fn (StorePage $p) => $this->pageArray($p)),
            'meta' => ['current_page' => $pages->currentPage(), 'last_page' => $pages->lastPage(), 'total' => $pages->total()],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /stores/{store}/pages/schema — the active theme's section library for the builder.
     * `sections_schema` entries are passed through untouched (label, description, category,
     * icon, settings with `{value,label}` options, translatable flags, list item fields).
     */
    public function schema(Request $request, Store $store): JsonResponse
    {
        $theme = $this->resolver->resolve($store);
        $version = $theme['theme_version_id'] ? ThemeVersion::query()->find($theme['theme_version_id']) : null;

        return response()->json([
            'sections_schema' => $theme['sections_schema'] ?? [],
            'theme' => ['key' => $theme['key'], 'version' => $theme['version']],
            'settings_schema' => $version?->settings_schema ?? [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /stores/{store}/pages/template/{template} — the singleton template page (`home`)
     * for `?locale` (default: store default locale). Created on first access, seeded from
     * the active theme manifest; 201 when created, 200 afterwards. Same payload as show().
     */
    public function ensureTemplate(Request $request, Store $store, string $template): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(LocaleContext::storeSupported($store))],
        ]);
        [$page, $created] = $this->service->ensureTemplatePage($store, $template, $data['locale'] ?? null);
        $page->load('sections');

        return response()->json(['data' => $this->pageArray($page, true)], $created ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $page): JsonResponse
    {
        $model = StorePage::query()->with('sections')->findOrFail($page);

        return response()->json(['data' => $this->pageArray($model, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $this->validatePage($request, $store);
        $page = $this->service->create($store, $data);

        return response()->json(['data' => $this->pageArray($page)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, Store $store, int $page): JsonResponse
    {
        $model = StorePage::query()->findOrFail($page);
        $model = $this->service->update($model, $this->validatePage($request, $store, false), $request->user()?->id);

        return response()->json(['data' => $this->pageArray($model)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** PUT /stores/{store}/pages/{page}/sections — full schema-driven layout. */
    public function syncSections(Request $request, Store $store, int $page): JsonResponse
    {
        $model = StorePage::query()->findOrFail($page);
        $data = $request->validate([
            'sections' => ['present', 'array', 'max:60'],
            'sections.*.type' => ['required', 'string', 'max:40'],
            'sections.*.settings' => ['nullable', 'array'],
            'sections.*.reusable_section_id' => ['nullable', 'integer'],
            'sections.*.is_visible' => ['nullable', 'boolean'],
        ]);
        $model = $this->service->syncSections($model, $data['sections'], $request->user()?->id);

        return response()->json(['data' => $this->pageArray($model, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function publish(Request $request, Store $store, int $page): JsonResponse
    {
        return $this->stateChange($this->service->publish(StorePage::query()->findOrFail($page)));
    }

    public function unpublish(Request $request, Store $store, int $page): JsonResponse
    {
        return $this->stateChange($this->service->unpublish(StorePage::query()->findOrFail($page)));
    }

    public function schedule(Request $request, Store $store, int $page): JsonResponse
    {
        $data = $request->validate(['publish_at' => ['required', 'date']]);
        $model = $this->service->schedule(StorePage::query()->findOrFail($page), new \DateTimeImmutable($data['publish_at']));

        return $this->stateChange($model);
    }

    public function destroy(Request $request, Store $store, int $page): JsonResponse
    {
        $this->service->delete(StorePage::query()->findOrFail($page));

        return response()->json(['message' => 'Deleted.'], 200);
    }

    /** POST /stores/{store}/pages/{page}/preview — signed draft preview URL. */
    public function preview(Request $request, Store $store, int $page): JsonResponse
    {
        $model = StorePage::query()->findOrFail($page);
        $token = $this->previewToken->makePage($store->id, $model->id, 1800);

        return response()->json([
            'preview_url' => $this->urls->previewUrl($store, $token, $model->template === 'home' ? '/' : '/pages/'.$model->slug),
            'expires_in' => 1800,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function revisions(Request $request, Store $store, int $page): JsonResponse
    {
        $model = StorePage::query()->findOrFail($page);
        $rows = $model->revisions()->with('createdBy:id,name')->limit(50)->get()
            ->map(fn (StorePageRevision $r) => [
                'id' => $r->id,
                'revision_number' => $r->revision_number,
                'created_by' => $r->createdBy?->name,
                'created_at' => $r->created_at,
                'sections_count' => count($r->snapshot['sections'] ?? []),
            ]);

        return response()->json(['data' => $rows], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function restoreRevision(Request $request, Store $store, int $page, int $revision): JsonResponse
    {
        $model = StorePage::query()->findOrFail($page);
        $rev = $model->revisions()->findOrFail($revision);
        $model = $this->service->restoreRevision($model, $rev, $request->user()?->id);

        return response()->json(['data' => $this->pageArray($model, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function stateChange(StorePage $page): JsonResponse
    {
        return response()->json(['data' => $this->pageArray($page)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** `locale` must be one of the store's supported locales; the service defaults it to the store default. */
    private function validatePage(Request $request, Store $store, bool $creating = true): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'template' => ['nullable', 'in:page,landing,home'],
            'locale' => ['nullable', 'string', Rule::in(LocaleContext::storeSupported($store))],
            'status' => ['nullable', 'in:draft,published,scheduled'],
            'seo' => ['nullable', 'array'],
            'publish_at' => ['nullable', 'date'],
        ]);
    }

    private function pageArray(StorePage $page, bool $withSections = false): array
    {
        $out = [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'template' => $page->template,
            'locale' => $page->locale,
            'seo' => $page->seo,
            'publish_at' => $page->publish_at,
            'published_at' => $page->published_at,
            'is_public' => $page->isPubliclyVisible(),
        ];
        if ($withSections) {
            $out['sections'] = $page->sections->map(fn ($s) => [
                'id' => $s->id, 'type' => $s->type, 'settings' => $s->settings,
                'reusable_section_id' => $s->reusable_section_id, 'position' => $s->position,
                'is_visible' => (bool) $s->is_visible,
            ])->values();
            $out['has_unpublished_changes'] = $this->service->hasUnpublishedChanges($page);
        }

        return $out;
    }
}
