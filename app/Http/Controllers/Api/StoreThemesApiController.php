<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\StoreThemeRevision;
use App\Models\Theme;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeSettingsValidator;
use App\Services\Themes\CustomCssSanitizer;
use App\Services\Storefront\StorefrontPageCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Owner/Admin theme management for a store. Mounted under /stores/{store}/themes
 * with the store.scope middleware, which authorizes store ownership (StorePolicy)
 * and sets the tenant — every action here is tenant-safe and owner-only.
 */
class StoreThemesApiController extends Controller
{
    public function __construct(
        private readonly StoreThemeService $service,
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettingsValidator $validator,
    ) {}

    /** GET /stores/{store}/themes — available themes + this store's installs. */
    public function index(Request $request, Store $store): JsonResponse
    {
        $installs = StoreTheme::query()->where('store_id', $store->id)->get()->keyBy('theme_id');

        $themes = Theme::query()->where('status', 'published')->orderBy('name')->get()->map(function (Theme $theme) use ($installs) {
            $install = $installs->get($theme->id);

            return [
                'id' => $theme->id,
                'key' => $theme->key,
                'name' => $theme->name,
                'author' => $theme->author,
                'latest_version' => $this->registry->resolveThemeVersion($theme)?->version,
                'installed' => $install !== null,
                'status' => $install?->status,
            ];
        });

        return response()->json([
            'data' => $themes,
            'active_theme_id' => $installs->firstWhere('status', 'active')?->theme_id,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/themes/{theme} — detail + settings schema + install. */
    public function show(Request $request, Store $store, int $theme): JsonResponse
    {
        $model = Theme::query()->findOrFail($theme);
        $version = $this->registry->resolveThemeVersion($model);
        $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $model->id)->first();

        return response()->json([
            'theme' => ['id' => $model->id, 'key' => $model->key, 'name' => $model->name],
            'version' => $version ? ['version' => $version->version, 'settings_schema' => $version->settings_schema] : null,
            'install' => $install ? [
                'id' => $install->id,
                'status' => $install->status,
                'settings' => $install->settings,
                'custom_css' => $install->custom_css,
            ] : null,
            'is_active' => $install?->status === 'active',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/themes/history — activation audit trail. */
    public function history(Request $request, Store $store): JsonResponse
    {
        $rows = StoreThemeActivation::query()
            ->where('store_id', $store->id)
            ->with(['theme:id,key,name', 'version:id,version', 'activatedBy:id,name'])
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn (StoreThemeActivation $a) => [
                'id' => $a->id,
                'theme' => $a->theme?->key,
                'version' => $a->version?->version,
                'action' => $a->action,
                'activated_by' => $a->activatedBy?->name,
                'activated_at' => $a->activated_at,
                'rollback_from_version_id' => $a->rollback_from_version_id,
                'rollback_to_version_id' => $a->rollback_to_version_id,
            ]);

        return response()->json(['data' => $rows], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /stores/{store}/themes/install */
    public function install(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'theme_id' => ['required', 'integer', 'exists:themes,id'],
            'version' => ['nullable', 'string'],
        ]);

        $theme = Theme::query()->where('id', $data['theme_id'])->where('status', 'published')->firstOrFail();
        $version = $this->registry->resolveThemeVersion($theme, $data['version'] ?? null);
        abort_if($version === null, 422, 'Theme version not found.');

        try {
            $install = $this->service->install($store, $theme, $version);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422); // incompatible
        }

        return response()->json(['data' => $this->installArray($install)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /stores/{store}/themes/activate */
    public function activate(Request $request, Store $store): JsonResponse
    {
        $install = $this->findInstall($request, $store);
        try {
            $this->service->activate($store, $install, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422); // incompatible
        }

        return response()->json(['data' => $this->installArray($install->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /stores/{store}/themes/upgrade — to a newer, compatible version. */
    public function upgrade(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'theme_id' => ['required', 'integer'],
            'version' => ['nullable', 'string'],
        ]);
        $theme = Theme::query()->findOrFail($data['theme_id']);

        try {
            $install = $this->service->upgrade($store, $theme, $data['version'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->installArray($install->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /stores/{store}/themes/preview — signed, expiring preview URL (optionally of a target version). */
    public function preview(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['theme_id' => ['required', 'integer'], 'version' => ['nullable', 'string']]);
        $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $data['theme_id'])->firstOrFail();

        $versionId = 0;
        if (! empty($data['version'])) {
            $target = $this->registry->resolveThemeVersion($install->theme_id, $data['version']);
            abort_if($target === null, 422, 'Preview version not found.');
            $versionId = $target->id;
        }

        return response()->json([
            'preview_url' => $this->service->previewUrl($store, $install, 1800, $versionId),
            'expires_in' => 1800,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /stores/{store}/themes/rollback */
    public function rollback(Request $request, Store $store): JsonResponse
    {
        try {
            $install = $this->service->rollback($store, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->installArray($install->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** PUT /stores/{store}/themes/settings — validate against schema, then save. */
    public function settings(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'theme_id' => ['required', 'integer'],
            'settings' => ['required', 'array'],
            'source' => ['nullable', 'in:manual,autosave'],
        ]);

        $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $data['theme_id'])->firstOrFail();
        $version = $install->version;
        $errors = $this->validator->errors($data['settings'], $version->settings_schema ?? []);
        if ($errors !== []) {
            return response()->json(['message' => 'Invalid settings.', 'errors' => ['settings' => $errors]], 422);
        }

        $install = $this->service->updateSettings(
            $install,
            $data['settings'],
            $request->user()?->id,
            $data['source'] ?? 'manual',
        );

        return response()->json(['data' => $this->installArray($install)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function revisions(Request $request, Store $store, int $theme): JsonResponse
    {
        $install = StoreTheme::query()
            ->where('store_id', $store->id)
            ->where('theme_id', $theme)
            ->firstOrFail();
        $rows = $install->revisions()
            ->with('createdBy:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (StoreThemeRevision $revision) => [
                'id' => $revision->id,
                'source' => $revision->source,
                'settings' => $revision->settings,
                'created_by' => $revision->createdBy?->name,
                'created_at' => $revision->created_at,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function customCss(Request $request, Store $store, CustomCssSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate(['theme_id' => ['required', 'integer'], 'custom_css' => ['nullable', 'string', 'max:50000']]);
        $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $data['theme_id'])->firstOrFail();
        $install->update(['custom_css' => $sanitizer->sanitize($data['custom_css'] ?? '')]);
        app(StorefrontPageCache::class)->flushStore($store->id);

        return response()->json(['data' => ['custom_css' => $install->custom_css]]);
    }

    public function restoreRevision(
        Request $request,
        Store $store,
        int $theme,
        int $revision,
    ): JsonResponse {
        $install = StoreTheme::query()
            ->where('store_id', $store->id)
            ->where('theme_id', $theme)
            ->firstOrFail();
        $model = $install->revisions()->whereKey($revision)->firstOrFail();
        $restored = $this->service->restoreRevision($install, $model, $request->user()?->id);

        return response()->json(['data' => $this->installArray($restored)]);
    }

    private function findInstall(Request $request, Store $store): StoreTheme
    {
        $data = $request->validate(['theme_id' => ['required', 'integer']]);

        return StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $data['theme_id'])->firstOrFail();
    }

    private function installArray(StoreTheme $install): array
    {
        return [
            'id' => $install->id,
            'theme_id' => $install->theme_id,
            'theme_version_id' => $install->theme_version_id,
            'status' => $install->status,
            'settings' => $install->settings,
            'custom_css' => $install->custom_css,
        ];
    }
}
