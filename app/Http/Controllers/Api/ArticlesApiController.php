<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

/**
 * Full article CRUD (admin) + unauthenticated public endpoints for /blog.
 *
 * Image pattern mirrors ProductsApiController::storeProductImage —
 * original + 16:9 thumbnail under storage/uploads/articles/*.
 */
class ArticlesApiController extends Controller
{
    // ---------- helpers ----------

    private function originalDir(): string
    {
        return public_path('storage/uploads/articles/original');
    }

    private function thumbnailDir(): string
    {
        return public_path('storage/uploads/articles/thumbnails');
    }

    private function ensureDirs(): void
    {
        foreach ([$this->originalDir(), $this->thumbnailDir()] as $d) {
            if (! File::exists($d)) {
                File::makeDirectory($d, 0755, true, true);
            }
        }
    }

    private function storeImage($file): string
    {
        $this->ensureDirs();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = md5($file->getClientOriginalName().microtime(true)).'.'.$ext;

        Image::make($file->getRealPath())->save($this->originalDir().'/'.$name, 90);
        Image::make($file->getRealPath())
            ->resize(1600, 900, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            })
            ->save($this->thumbnailDir().'/'.$name, 85);

        return $name;
    }

    private function deleteImageFiles(?string $name): void
    {
        if (! $name || str_contains($name, '/') || str_contains($name, '\\')) {
            return;
        }
        foreach ([$this->originalDir().'/'.$name, $this->thumbnailDir().'/'.$name] as $p) {
            if (File::exists($p)) {
                @unlink($p);
            }
        }
    }

    private function imageUrl(?string $name, string $variant = 'thumbnails'): ?string
    {
        if (! $name) {
            return null;
        }
        return asset('storage/uploads/articles/'.$variant.'/'.$name);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: Str::random(8);
        $candidate = $slug;
        $i = 2;
        while (Article::where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = $slug.'-'.$i;
            $i++;
            if ($i > 50) {
                $candidate = $slug.'-'.Str::random(6);
                break;
            }
        }
        return $candidate;
    }

    private function serializeAdmin(Article $a): array
    {
        return [
            'id' => $a->id,
            'slug' => $a->slug,
            'title' => $a->title,
            'title_ar' => $a->title_ar,
            'excerpt' => $a->excerpt,
            'excerpt_ar' => $a->excerpt_ar,
            'content' => $a->content,
            'content_ar' => $a->content_ar,
            'meta_title' => $a->meta_title,
            'meta_title_ar' => $a->meta_title_ar,
            'meta_description' => $a->meta_description,
            'meta_description_ar' => $a->meta_description_ar,
            'featured_image' => $a->featured_image,
            'featured_image_url' => $this->imageUrl($a->featured_image, 'original'),
            'featured_image_thumb_url' => $this->imageUrl($a->featured_image, 'thumbnails'),
            'published' => (bool) $a->published,
            'published_at' => $a->published_at?->toIso8601String(),
            'created_by' => $a->created_by,
            'author' => $a->author?->only(['id', 'name']),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    private function serializePublic(Article $a, string $lang, bool $full = false): array
    {
        $l = $a->localized($lang);
        $out = [
            'id' => $a->id,
            'slug' => $a->slug,
            'title' => $l['title'],
            'excerpt' => $l['excerpt'],
            'featured_image_url' => $this->imageUrl($a->featured_image, 'thumbnails'),
            'featured_image_full_url' => $this->imageUrl($a->featured_image, 'original'),
            'published_at' => $a->published_at?->toIso8601String(),
            'author' => $a->author?->only(['id', 'name']),
        ];
        if ($full) {
            $out['content'] = $l['content'];
            $out['meta_title'] = $l['meta_title'];
            $out['meta_description'] = $l['meta_description'];
        }
        return $out;
    }

    private function resolveLang(Request $request): string
    {
        $q = strtolower((string) $request->query('lang', ''));
        if ($q === 'ar' || $q === 'en') {
            return $q;
        }
        $hdr = strtolower((string) $request->header('Accept-Language', ''));
        return str_starts_with($hdr, 'ar') ? 'ar' : 'en';
    }

    private function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        // Strip <script> blocks entirely and on* handler attributes — minimal XSS shield.
        $clean = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $clean = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', (string) $clean);
        $clean = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', (string) $clean);
        $clean = preg_replace('#javascript\s*:#i', '', (string) $clean);
        return $clean;
    }

    // ---------- admin endpoints ----------

    public function adminIndex(Request $request): JsonResponse
    {
        $q = Article::query()->with('author:id,name')->latest();
        if ($s = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('title', 'like', "%{$s}%")
                    ->orWhere('title_ar', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%");
            });
        }
        if ($request->has('published')) {
            $q->where('published', $request->boolean('published'));
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $rows = $q->paginate($perPage);

        return response()->json([
            'data' => collect($rows->items())->map(fn ($a) => $this->serializeAdmin($a))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    public function adminShow(int $id): JsonResponse
    {
        $a = Article::with('author:id,name')->findOrFail($id);
        return response()->json(['data' => $this->serializeAdmin($a)]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'excerpt_ar' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'content_ar' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_title_ar' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_description_ar' => ['nullable', 'string', 'max:500'],
            'published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $article = new Article;
        $article->title = $data['title'];
        $article->title_ar = $data['title_ar'] ?? null;
        $article->slug = $this->uniqueSlug($data['slug'] ?: $data['title']);
        $article->excerpt = $data['excerpt'] ?? null;
        $article->excerpt_ar = $data['excerpt_ar'] ?? null;
        $article->content = $this->sanitize($data['content'] ?? null);
        $article->content_ar = $this->sanitize($data['content_ar'] ?? null);
        $article->meta_title = $data['meta_title'] ?? null;
        $article->meta_title_ar = $data['meta_title_ar'] ?? null;
        $article->meta_description = $data['meta_description'] ?? null;
        $article->meta_description_ar = $data['meta_description_ar'] ?? null;
        $article->published = $request->boolean('published', false);
        $article->published_at = $request->input('published_at')
            ? now()->parse($request->input('published_at'))
            : ($article->published ? now() : null);
        $article->created_by = $request->user()?->id;

        if ($request->hasFile('featured_image')) {
            $article->featured_image = $this->storeImage($request->file('featured_image'));
        }

        $article->save();

        return response()->json(['data' => $this->serializeAdmin($article->fresh('author'))], 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'excerpt_ar' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'content_ar' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_title_ar' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_description_ar' => ['nullable', 'string', 'max:500'],
            'published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) {
            $article->title = $data['title'];
        }
        foreach (['title_ar', 'excerpt', 'excerpt_ar', 'meta_title', 'meta_title_ar', 'meta_description', 'meta_description_ar'] as $k) {
            if (array_key_exists($k, $data)) {
                $article->{$k} = $data[$k];
            }
        }
        foreach (['content', 'content_ar'] as $k) {
            if (array_key_exists($k, $data)) {
                $article->{$k} = $this->sanitize($data[$k]);
            }
        }
        if (! empty($data['slug'])) {
            $article->slug = $this->uniqueSlug($data['slug'], $article->id);
        }
        if ($request->has('published')) {
            $wasPublished = (bool) $article->published;
            $article->published = $request->boolean('published');
            if ($article->published && ! $wasPublished && ! $article->published_at) {
                $article->published_at = now();
            }
        }
        if ($request->filled('published_at')) {
            $article->published_at = now()->parse($request->input('published_at'));
        }

        if ($request->boolean('remove_image') && $article->featured_image) {
            $this->deleteImageFiles($article->featured_image);
            $article->featured_image = null;
        }
        if ($request->hasFile('featured_image')) {
            if ($article->featured_image) {
                $this->deleteImageFiles($article->featured_image);
            }
            $article->featured_image = $this->storeImage($request->file('featured_image'));
        }

        $article->save();

        return response()->json(['data' => $this->serializeAdmin($article->fresh('author'))]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        if ($article->featured_image) {
            $this->deleteImageFiles($article->featured_image);
        }
        $article->delete();

        return response()->json(['ok' => true]);
    }

    public function adminPublish(int $id): JsonResponse
    {
        $a = Article::findOrFail($id);
        $a->published = true;
        if (! $a->published_at) {
            $a->published_at = now();
        }
        $a->save();

        return response()->json(['data' => $this->serializeAdmin($a->fresh('author'))]);
    }

    public function adminUnpublish(int $id): JsonResponse
    {
        $a = Article::findOrFail($id);
        $a->published = false;
        $a->save();

        return response()->json(['data' => $this->serializeAdmin($a->fresh('author'))]);
    }

    public function adminUploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $name = $this->storeImage($request->file('image'));

        return response()->json([
            'url' => $this->imageUrl($name, 'original'),
            'thumb_url' => $this->imageUrl($name, 'thumbnails'),
            'filename' => $name,
        ]);
    }

    // ---------- public endpoints ----------

    public function publicIndex(Request $request): JsonResponse
    {
        $lang = $this->resolveLang($request);
        $perPage = min(48, max(1, (int) $request->query('per_page', 12)));

        $q = Article::query()->published()->with('author:id,name')->latest('published_at')->latest('id');
        if ($s = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('title', 'like', "%{$s}%")
                    ->orWhere('title_ar', 'like', "%{$s}%")
                    ->orWhere('excerpt', 'like', "%{$s}%")
                    ->orWhere('excerpt_ar', 'like', "%{$s}%");
            });
        }
        $rows = $q->paginate($perPage);

        return response()->json([
            'data' => collect($rows->items())->map(fn ($a) => $this->serializePublic($a, $lang, false))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'lang' => $lang,
            ],
        ]);
    }

    public function publicShow(Request $request, string $slug): JsonResponse
    {
        $lang = $this->resolveLang($request);
        $article = Article::query()->published()->with('author:id,name')->where('slug', $slug)->first();
        if (! $article) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $related = Article::query()
            ->published()
            ->where('id', '!=', $article->id)
            ->latest('published_at')
            ->limit(3)
            ->get()
            ->map(fn ($a) => $this->serializePublic($a, $lang, false))
            ->values()
            ->all();

        return response()->json([
            'data' => $this->serializePublic($article, $lang, true),
            'related' => $related,
        ]);
    }

    public function publicFeed(Request $request)
    {
        $lang = $this->resolveLang($request);
        $articles = Article::query()->published()->latest('published_at')->limit(20)->get();

        $site = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'https://b2b-sellcahase.wigpleasure.com')), '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<rss version="2.0"><channel>';
        $xml .= '<title>Sellchaze Blog</title>';
        $xml .= '<link>'.htmlspecialchars($site.'/blog', ENT_XML1).'</link>';
        $xml .= '<description>Latest articles from Sellchaze</description>';
        foreach ($articles as $a) {
            $l = $a->localized($lang);
            $xml .= '<item>';
            $xml .= '<title>'.htmlspecialchars((string) $l['title'], ENT_XML1).'</title>';
            $xml .= '<link>'.htmlspecialchars($site.'/blog/'.$a->slug, ENT_XML1).'</link>';
            $xml .= '<guid isPermaLink="true">'.htmlspecialchars($site.'/blog/'.$a->slug, ENT_XML1).'</guid>';
            $xml .= '<pubDate>'.($a->published_at?->toRfc2822String() ?? now()->toRfc2822String()).'</pubDate>';
            if ($l['excerpt']) {
                $xml .= '<description>'.htmlspecialchars((string) $l['excerpt'], ENT_XML1).'</description>';
            }
            $xml .= '</item>';
        }
        $xml .= '</channel></rss>';

        return response($xml, 200)->header('Content-Type', 'application/rss+xml; charset=utf-8');
    }
}
