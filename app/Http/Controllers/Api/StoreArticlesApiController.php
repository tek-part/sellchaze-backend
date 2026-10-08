<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreArticle;
use App\Services\Articles\ArticlePreviewToken;
use App\Services\Articles\ImportLegacyArticles;
use App\Services\Articles\StoreBlogContent;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontUrlGenerator;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreArticlesApiController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'published', 'scheduled', 'archived'])],
            'search' => ['nullable', 'string', 'max:180'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = StoreArticle::query()->where('store_id', $store->id);
        if (($status = $input['status'] ?? null) !== null) {
            $query->where('archived', $status === 'archived');
            if ($status === 'draft') {
                $query->whereNull('publication')->whereNull('scheduled_publication');
            } elseif ($status === 'scheduled') {
                $query->whereNotNull('scheduled_publication')->where('scheduled_at', '>', now());
            } elseif ($status === 'published') {
                $query->where(function ($q) {
                    $q->where(function ($due) {
                        $due->whereNotNull('scheduled_publication')->where('scheduled_at', '<=', now());
                    })->orWhere(function ($live) {
                        $live->whereNull('scheduled_publication')->whereNotNull('publication')->where('publish_at', '<=', now());
                    });
                });
            }
        }
        if ($search = $input['search'] ?? null) {
            $query->where(function ($q) use ($search, $store) {
                $q->where('slug', 'like', '%'.$search.'%');
                foreach (LocaleContext::storeSupported($store) as $locale) {
                    $q->orWhere('draft->'.$locale.'->title', 'like', '%'.$search.'%');
                }
            });
        }
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($input['per_page'] ?? 20);
        $rows->getCollection()->transform(fn (StoreArticle $article) => $this->resource($article, false));

        return response()->json([...$rows->toArray(), 'legacy_count' => count(app(StoreBlogContent::class)->reservedLegacySlugs($store))]);
    }

    public function importLegacy(Request $request, Store $store, ImportLegacyArticles $importer): JsonResponse
    {
        $ids = $importer->import($store, $request->user()?->id);

        return response()->json(['imported' => count($ids), 'article_ids' => $ids]);
    }

    public function show(Request $request, Store $store, int $article): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->find($store, $article))]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $this->validatedDraft($request, $store);
        $article = DB::transaction(function () use ($store, $data) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_if(StoreArticle::query()->where('store_id', $store->id)->where('slug', $data['slug'])->exists(), 422, 'This article URL is already in use.');

            abort_if(in_array($data['slug'], app(StoreBlogContent::class)->reservedLegacySlugs($store), true), 422, 'This URL is already used by an existing blog article.');

            return StoreArticle::create(['store_id' => $store->id, ...$data]);
        });

        return response()->json(['data' => $this->resource($article)], 201);
    }

    public function update(Request $request, Store $store, int $article): JsonResponse
    {
        $data = $this->validatedDraft($request, $store, $article);

        return $this->mutate($request, $store, $article, function (StoreArticle $row) use ($data, $store) {
            // Keep public URLs stable after the first publication, including scheduled posts.
            abort_if(($row->publication !== null || $row->scheduled_publication !== null) && $row->slug !== $data['slug'], 422, 'The URL of a published article cannot be changed.');
            abort_if(StoreArticle::query()->where('store_id', $store->id)->where('slug', $data['slug'])->whereKeyNot($row->id)->exists(), 422, 'This article URL is already in use.');
            abort_if(in_array($data['slug'], app(StoreBlogContent::class)->reservedLegacySlugs($store), true), 422, 'This URL is already used by an existing blog article.');
            $row->fill($data);
        });
    }

    public function publish(Request $request, Store $store, int $article): JsonResponse
    {
        $input = $request->validate(['publish_at' => ['nullable', 'date', 'after_or_equal:now']]);

        return $this->mutate($request, $store, $article, function (StoreArticle $row) use ($store, $input) {
            $title = $row->draft[LocaleContext::storeDefault($store)]['title'] ?? '';
            abort_if(trim($title) === '', 422, 'Add a title in the default store language before publishing.');
            if (! empty($input['publish_at'])) {
                // Promote a previously due schedule before replacing it with the next one.
                if ($row->scheduled_at !== null && ! $row->scheduled_at->isFuture()) {
                    $row->publication = $row->scheduled_publication;
                    $row->publish_at = $row->scheduled_at;
                }
                $row->scheduled_publication = $row->draft;
                $row->scheduled_at = $input['publish_at'];
            } else {
                $row->publication = $row->draft;
                $row->publish_at = now();
                $row->scheduled_publication = null;
                $row->scheduled_at = null;
            }
            $row->archived = false;
        });
    }

    public function preview(Request $request, Store $store, int $article, ArticlePreviewToken $tokens, StorefrontUrlGenerator $urls): JsonResponse
    {
        $row = $this->find($store, $article);
        $url = $urls->previewUrl($store, rawurlencode($tokens->make($row)), '/blog/'.rawurlencode($row->slug));
        $url = $url ? str_replace('__preview=', 'article_preview=', $url) : null;
        abort_if(! $url, 422, 'Storefront preview host is not configured.');

        return response()->json(['preview_url' => $url, 'expires_in' => 1800], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function unpublish(Request $request, Store $store, int $article): JsonResponse
    {
        return $this->mutate($request, $store, $article, function (StoreArticle $row) {
            $row->publication = null;
            $row->publish_at = null;
            $row->scheduled_publication = null;
            $row->scheduled_at = null;
            $row->archived = false;
        });
    }

    public function archive(Request $request, Store $store, int $article): JsonResponse
    {
        return $this->mutate($request, $store, $article, function (StoreArticle $row) {
            $row->archived = true;
        });
    }

    private function find(Store $store, int $id): StoreArticle
    {
        return StoreArticle::query()->where('store_id', $store->id)->whereKey($id)->firstOrFail();
    }

    private function mutate(Request $request, Store $store, int $id, callable $change): JsonResponse
    {
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $article = DB::transaction(function () use ($store, $id, $version, $change) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $row = StoreArticle::query()->where('store_id', $store->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($row->version !== $version, 409, 'This article changed in another session. Reload before saving.');
            $change($row);
            $row->version++;
            $row->save();
            DB::afterCommit(fn () => app(StorefrontPageCache::class)->flushStore($store->id));

            return $row;
        });

        return response()->json(['data' => $this->resource($article)]);
    }

    private function validatedDraft(Request $request, Store $store, ?int $id = null): array
    {
        if (is_string($request->input('slug'))) {
            $slug = $request->input('slug');
            if (class_exists(\Normalizer::class)) {
                $slug = \Normalizer::normalize($slug, \Normalizer::FORM_KC) ?: $slug;
            }
            $request->merge(['slug' => mb_strtolower(trim($slug))]);
        }
        $existing = $id ? $this->find($store, $id) : null;
        $locales = array_unique([...LocaleContext::storeSupported($store), ...array_keys($existing?->draft ?? [])]);
        abort_if(in_array($request->input('slug'), app(StoreBlogContent::class)->reservedLegacySlugs($store), true), 422, 'This URL is already used by an existing blog article.');
        $rules = [
            'slug' => ['required', 'string', 'max:180', 'regex:/^[\pL\pN]+(?:[-_][\pL\pN]+)*$/u', Rule::unique('store_articles')->where('store_id', $store->id)->ignore($id)],
            'draft' => ['required', 'array:'.implode(',', $locales)],
        ];
        if ($existing?->legacy_positions !== null && $request->input('slug') === $existing->slug) {
            $rules['slug'] = ['required', 'string', 'max:180'];
        }
        foreach ($locales as $locale) {
            $prefix = 'draft.'.$locale;
            $rules[$prefix] = ['sometimes', 'array:title,excerpt,body,image,seo_title,seo_description,date'];
            foreach (['title' => 200, 'excerpt' => 1000, 'body' => 100000, 'seo_title' => 200, 'seo_description' => 500, 'date' => 100] as $key => $max) {
                $rules[$prefix.'.'.$key] = ['nullable', 'string', 'max:'.$max];
            }
            $rules[$prefix.'.image'] = ['nullable', 'url:http,https', 'max:2048'];
        }

        return $request->validate($rules);
    }

    private function resource(StoreArticle $row, bool $includeBody = true): array
    {
        return [
            'id' => $row->id, 'slug' => $row->slug, 'draft' => $includeBody ? $row->draft : collect($row->draft)->map(fn ($translation) => ['title' => $translation['title'] ?? '', 'image' => $translation['image'] ?? null])->all(),
            'status' => $row->editorialStatus(), 'version' => $row->version,
            'publish_at' => ($row->scheduled_at ?? $row->publish_at)?->toIso8601String(),
            'is_live' => $row->publicSnapshot() !== null,
            'has_unpublished_changes' => $row->draft !== ($row->scheduled_publication ?? $row->publication),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
