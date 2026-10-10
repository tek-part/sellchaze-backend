<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePage;
use App\Services\PageBuilder\StorePageService;
use App\Services\Storefront\StorefrontUrlGenerator;
use App\Support\Localization\LocalizedValue;
use App\Support\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreSimplePagesController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.pages.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    private function page(Store $store, int $id): StorePage
    {
        return StorePage::withoutGlobalScopes()->where('store_id', $store->id)->where('template', 'simple')->findOrFail($id);
    }

    private function data(StorePage $page, Store $store): array
    {
        return ['id' => $page->id, 'slug' => $page->slug, 'active' => $page->status === 'published',
            ...($page->simple_configuration ?? []), 'public_path' => $page->publicPath(),
            'public_url' => app(StorefrontUrlGenerator::class)->publicUrl($store, $page->publicPath())];
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'], 'archived' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:title,slug,position,active,show_in_header,show_in_footer'], 'direction' => ['nullable', 'in:asc,desc']]);
        $query = StorePage::withoutGlobalScopes()->where('store_id', $store->id)->where('template', 'simple')
            ->where('simple_configuration->archived', $request->boolean('archived'));
        if (($q = trim($data['q'] ?? '')) !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(fn ($query) => $query->where('slug', 'like', $needle)->orWhere('simple_configuration->title->ar', 'like', $needle)->orWhere('simple_configuration->title->en', 'like', $needle));
        }
        $sort = $data['sort'] ?? 'position';
        $query->orderBy(match ($sort) {
            'position', 'show_in_header', 'show_in_footer' => 'simple_configuration->'.$sort, 'active' => 'status', default => $sort
        }, $data['direction'] ?? 'asc')->orderBy('id');
        $rows = $query->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $rows->getCollection()->map(fn ($page) => $this->data($page, $store)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()]]);
    }

    public function show(Request $request, Store $store, int $page): JsonResponse
    {
        $store = $this->store($request);

        return response()->json(['data' => $this->data($this->page($store, $page), $store)]);
    }

    public function save(Request $request, Store $store, StorePageService $service, ?int $page = null): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'not_in:home,shipping,returns'],
            'title' => ['required', 'array:ar,en'], 'title.ar' => ['present', 'nullable', 'string', 'max:255'], 'title.en' => ['present', 'nullable', 'string', 'max:255'],
            'content' => ['required', 'array:ar,en'], 'content.ar' => ['present', 'nullable', 'string', 'max:20000'], 'content.en' => ['present', 'nullable', 'string', 'max:20000'],
            'active' => ['required', 'boolean'], 'show_in_header' => ['required', 'boolean'], 'show_in_footer' => ['required', 'boolean'], 'position' => ['required', 'integer', 'min:0', 'max:100000']]);
        $title = ['ar' => trim($data['title']['ar'] ?? ''), 'en' => trim($data['title']['en'] ?? '')];
        if ($title['ar'] === '' && $title['en'] === '') {
            throw ValidationException::withMessages(['title' => 'Enter a title in at least one language.']);
        }
        $saved = DB::transaction(function () use ($store, $page, $data, $title, $service) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $model = $page === null ? new StorePage : $this->page($store, $page);
            abort_unless(! ($model->simple_configuration['archived'] ?? false), 409, 'Restore this archived page before editing.');
            abort_unless((int) ($model->simple_configuration['version'] ?? 0) === (int) $data['version'], 409, 'This page changed. Reload before saving.');
            if (StorePage::withoutGlobalScopes()->where('store_id', $store->id)->when($page !== null, fn ($query) => $query->where('id', '!=', $page))
                ->where(fn ($query) => $query->where('slug', $data['slug'])->orWhere('published_slug', $data['slug']))->exists()) {
                throw ValidationException::withMessages(['slug' => 'This URL is already used by another store page.']);
            }
            $model->fill(['store_id' => $store->id, 'template' => 'simple', 'locale' => $store->default_locale ?: 'en',
                'slug' => $data['slug'], 'title' => LocalizedValue::pick($title, $store->default_locale ?: 'en'),
                'simple_configuration' => ['title' => $title, 'content' => ['ar' => ProductDescription::clean($data['content']['ar'], true) ?? '', 'en' => ProductDescription::clean($data['content']['en'], true) ?? ''],
                    'show_in_header' => (bool) $data['show_in_header'], 'show_in_footer' => (bool) $data['show_in_footer'],
                    'position' => (int) $data['position'], 'version' => (int) $data['version'] + 1, 'archived' => false],
                'status' => 'draft', 'publish_at' => null]);
            $model->save();

            return $data['active'] ? $service->publish($model) : $service->unpublish($model);
        });

        return response()->json(['data' => $this->data($saved, $store)], $page === null ? 201 : 200);
    }

    /** Archiving is reversible; a bulk request succeeds entirely or changes nothing. */
    public function archive(Request $request, Store $store, StorePageService $service): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['pages' => ['required', 'array', 'min:1', 'max:100'], 'pages.*' => ['required', 'array:id,version'],
            'pages.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'pages.*.version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($store, $data, $service) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $models = [];
            foreach ($data['pages'] as $row) {
                $model = $this->page($store, $row['id']);
                abort_unless((int) $model->simple_configuration['version'] === (int) $row['version'], 409, 'A selected page changed. Reload before archiving.');
                $models[] = $model;
            }
            foreach ($models as $model) {
                $config = $model->simple_configuration;
                $config['archived'] = true;
                $config['version']++;
                $model->update(['simple_configuration' => $config]);
                $service->unpublish($model);
            }
        });

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(Request $request, Store $store, int $page): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($store, $page, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $model = $this->page($store, $page);
            abort_unless((int) $model->simple_configuration['version'] === (int) $data['version'], 409, 'This page changed. Reload before restoring.');
            abort_unless($model->simple_configuration['archived'] ?? false, 409, 'Only archived pages can be restored.');
            $config = $model->simple_configuration;
            $config['archived'] = false;
            $config['version']++;
            $model->update(['simple_configuration' => $config]);

            return $model;
        });

        return response()->json(['data' => $this->data($model, $store)]);
    }
}
