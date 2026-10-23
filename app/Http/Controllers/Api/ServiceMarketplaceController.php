<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceService;
use App\Models\MarketplaceServiceProvider;
use App\Models\User;
use App\Services\ServiceMarketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServiceMarketplaceController extends Controller
{
    public function __construct(private readonly ServiceMarketplace $market) {}

    private function publisher(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['Merchant']), 403);

        return $user;
    }

    private function ownedProvider(Request $request): MarketplaceServiceProvider
    {
        return MarketplaceServiceProvider::where('user_id', $this->publisher($request)->id)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(ServiceMarketplace::CATEGORIES))], 'provider' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:rating,newest,oldest,price_asc,price_desc'], 'currency' => ['nullable', Rule::in(ServiceMarketplace::CURRENCIES)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:48']]);
        $query = $this->market->visible()->with('provider');
        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (isset($data['provider'])) {
            $query->where('provider_id', $data['provider']);
        }
        $sort = $data['sort'] ?? 'rating';
        $currency = $data['currency'] ?? (str_starts_with($sort, 'price_') ? 'EGP' : null);
        if ($currency) {
            $query->where('currency', $currency);
        }
        match ($sort) {
            'newest' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            'price_asc' => $query->orderBy('price_minor'),
            'price_desc' => $query->orderByDesc('price_minor'),
            default => $query->orderByDesc('ratings_count')->orderByDesc('rating'),
        };
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 16);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => $this->market->service($row)),
            'categories' => collect(ServiceMarketplace::CATEGORIES)->map(fn ($labels, $key) => ['key' => $key] + $labels)->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'currency_filter' => $currency]]);
    }

    public function show(Request $request, string $service): JsonResponse
    {
        $row = $this->market->visible()->with('provider')->whereKey($service)->firstOrFail();
        $related = $this->market->visible()->with('provider')->where('category', $row->category)->whereKeyNot($row->id)->orderBy('ratings_count')->orderBy('rating')->orderBy('id')->limit(16)->get();

        return response()->json(['data' => $this->market->service($row, true), 'related' => $related->map(fn ($item) => $this->market->service($item)),
            'can_rate' => (int) $row->provider->user_id !== (int) $request->user()->id,
            'my_rating' => DB::table('marketplace_service_ratings')->where('service_id', $row->id)->where('user_id', $request->user()->id)->value('rating')]);
    }

    public function provider(Request $request, string $provider): JsonResponse
    {
        $row = MarketplaceServiceProvider::where('published', true)->whereHas('owner', fn ($q) => $q->where('is_active', true)->where('pending_approval', false))->whereKey($provider)->firstOrFail();

        return response()->json(['data' => $this->market->provider($row), 'services_count' => $this->market->visible()->where('provider_id', $row->id)->count()]);
    }

    public function mine(Request $request): JsonResponse
    {
        $row = MarketplaceServiceProvider::where('user_id', $this->publisher($request)->id)->first();
        $services = $row ? MarketplaceService::with('provider')->where('provider_id', $row->id)->latest()->get() : collect();

        return response()->json(['provider' => $row ? $this->market->provider($row, true) : null, 'data' => $services->map(fn ($item) => $this->market->service($item, true, true))]);
    }

    public function saveProvider(Request $request): JsonResponse
    {
        $user = $this->publisher($request);
        $data = $request->validate(['display_name' => ['required', 'string', 'max:100'], 'bio' => ['nullable', 'string', 'max:2000'], 'country' => ['nullable', 'string', 'max:80'], 'avatar' => ['nullable', 'string', 'max:160'],
            'headline' => ['nullable', 'string', 'max:180'], 'skills' => ['nullable', 'array', 'max:20'], 'skills.*' => ['required', 'string', 'max:80', 'distinct'],
            'portfolio' => ['nullable', 'array', 'max:20'], 'portfolio.*' => ['required', 'array:id,title,description,url,images'],
            'portfolio.*.id' => ['required', 'uuid', 'distinct'], 'portfolio.*.title' => ['required', 'string', 'max:180'], 'portfolio.*.description' => ['nullable', 'string', 'max:2000'],
            'portfolio.*.url' => ['nullable', 'url:http,https', 'max:2048'], 'portfolio.*.images' => ['present', 'array', 'max:8'], 'portfolio.*.images.*' => ['required', 'string', 'max:160', 'distinct'],
            'contacts_public' => ['required', 'boolean'], 'published' => ['required', 'boolean'], 'version' => ['nullable', 'integer', 'min:1'],
            'contacts' => ['nullable', 'array:email,phone,whatsapp,telegram'], 'contacts.email' => ['nullable', 'email', 'max:180'],
            'contacts.phone' => ['nullable', 'string', 'regex:/^\+?[1-9][0-9]{7,14}$/'], 'contacts.whatsapp' => ['nullable', 'string', 'regex:/^\+?[1-9][0-9]{7,14}$/'],
            'contacts.telegram' => ['nullable', 'string', 'regex:/^@?(?:[A-Za-z][A-Za-z0-9_]{4,31}|\+?[1-9][0-9]{7,14})$/']]);
        $provider = DB::transaction(function () use ($user, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = MarketplaceServiceProvider::where('user_id', $user->id)->lockForUpdate()->first();
            if ($row) {
                abort_unless(($data['version'] ?? null) === (int) $row->version, 409, 'Provider profile changed. Reload before saving.');
                $this->market->assertImages($row, isset($data['avatar']) ? [$data['avatar']] : []);
                foreach ($data['portfolio'] ?? [] as $work) {
                    $this->market->assertImages($row, $work['images']);
                }
                $row->fill(array_diff_key($data, ['version' => true]));
                $row->version++;
                $row->save();
            } else {
                abort_if(isset($data['version']), 409, 'Provider profile does not exist yet.');
                abort_if(! empty($data['avatar']), 422, 'Create the provider profile before uploading its image.');
                foreach ($data['portfolio'] ?? [] as $work) {
                    abort_if(! empty($work['images']), 422, 'Create the provider profile before uploading work images.');
                }
                $row = MarketplaceServiceProvider::create(['id' => (string) Str::uuid(), 'user_id' => $user->id] + array_diff_key($data, ['version' => true]));
            }

            return $row;
        });

        return response()->json(['data' => $this->market->provider($provider, true)]);
    }

    private function serviceData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:30000'],
            'category' => ['required', Rule::in(array_keys(ServiceMarketplace::CATEGORIES))], 'price_minor' => ['required', 'integer', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(ServiceMarketplace::CURRENCIES)], 'images' => ['nullable', 'array', 'max:8'], 'images.*' => ['required', 'string', 'max:160', 'distinct'], 'published' => ['required', 'boolean']]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = strtolower($request->validate(['request_key' => ['required', 'uuid']])['request_key']);
        $data = $this->serviceData($request);
        $provider = $this->ownedProvider($request);
        $row = DB::transaction(function () use ($provider, $data, $key) {
            $current = MarketplaceServiceProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$current->id, $data['title'], $data['description'], $data['category'], $data['price_minor'], $data['currency'], $data['images'] ?? [], $data['published']], JSON_THROW_ON_ERROR));
            $existing = MarketplaceService::withTrashed()->whereKey($key)->first();
            if ($existing) {
                abort_unless($existing->provider_id === $current->id && hash_equals($existing->creation_hash, $hash) && ! $existing->trashed(), 409, 'Service request key already used.');

                return $existing;
            }
            abort_if($data['published'] && ! $current->published, 422, 'Publish the provider profile first.');
            $this->market->assertImages($current, $data['images'] ?? []);

            return MarketplaceService::create(['id' => $key, 'provider_id' => $current->id, 'creation_hash' => $hash] + $data);
        });

        return response()->json(['data' => $this->market->service($row, true, true)], 201);
    }

    public function update(Request $request, string $service): JsonResponse
    {
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $data = $this->serviceData($request);
        $provider = $this->ownedProvider($request);
        $row = DB::transaction(function () use ($provider, $service, $version, $data) {
            $current = MarketplaceServiceProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $row = MarketplaceService::where('provider_id', $current->id)->whereKey($service)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === $version, 409, 'Service changed. Reload before saving.');
            abort_if($data['published'] && ! $current->published, 422, 'Publish the provider profile first.');
            $this->market->assertImages($current, $data['images'] ?? []);
            $row->fill($data);
            $row->version++;
            $row->save();

            return $row;
        });

        return response()->json(['data' => $this->market->service($row, true, true)]);
    }

    public function destroy(Request $request, string $service): JsonResponse
    {
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $provider = $this->ownedProvider($request);
        DB::transaction(function () use ($provider, $service, $version) {
            $row = MarketplaceService::where('provider_id', $provider->id)->whereKey($service)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === $version, 409);
            $row->delete();
        });

        return response()->json(['archived' => true]);
    }

    public function rate(Request $request, string $service): JsonResponse
    {
        $rating = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5']])['rating'];
        DB::transaction(function () use ($request, $service, $rating) {
            $row = $this->market->visible()->whereKey($service)->lockForUpdate()->firstOrFail();
            abort_if((int) $row->provider->user_id === (int) $request->user()->id, 422, 'You cannot rate your own service.');
            $before = DB::table('marketplace_service_ratings')->where('service_id', $row->id)->where('user_id', $request->user()->id)->first();
            DB::table('marketplace_service_ratings')->updateOrInsert(['service_id' => $row->id, 'user_id' => $request->user()->id], ['rating' => $rating, 'created_at' => $before->created_at ?? now(), 'updated_at' => now()]);
            $row->ratings_count += $before ? 0 : 1;
            $row->ratings_sum += $rating - ($before->rating ?? 0);
            $row->rating = number_format($row->ratings_sum / $row->ratings_count, 2, '.', '');
            $row->save();
        });

        return $this->show($request, $service);
    }

    public function upload(Request $request): JsonResponse
    {
        $provider = $this->ownedProvider($request);
        abort_if(count(Storage::disk('local')->files('marketplace-service-media/'.$provider->id)) >= 64, 422, 'Provider image quota reached.');
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        $file = $request->file('image');
        $extension = $file->guessExtension();
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        abort_unless(in_array($extension, ['png', 'jpg', 'webp'], true), 422);
        $path = $file->storeAs('marketplace-service-media/'.$provider->id, Str::uuid().'.'.$extension, 'local');
        abort_unless($path, 500);

        return response()->json(['path' => $path], 201);
    }

    public function media(string $provider, string $file): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}\.(?:png|jpg|webp)$/', $file), 404);
        $path = 'marketplace-service-media/'.$provider.'/'.$file;
        // Draft and orphan uploads never become publicly readable through this route.
        $profile = MarketplaceServiceProvider::whereKey($provider)->where('published', true)->whereHas('owner', fn ($q) => $q->where('is_active', true)->where('pending_approval', false))->first();
        $portfolioImage = $profile && collect($profile->portfolio ?? [])->contains(fn ($work) => in_array($path, $work['images'] ?? [], true));
        $visible = $this->market->visible()->where('provider_id', $provider)->whereJsonContains('images', $path)->exists()
            || ($profile && $profile->avatar === $path) || $portfolioImage;
        abort_unless($visible && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function privateMedia(Request $request, string $file): BinaryFileResponse
    {
        $provider = $this->ownedProvider($request);
        abort_unless(preg_match('/^[a-f0-9-]{36}\.(?:png|jpg|webp)$/', $file), 404);
        $path = 'marketplace-service-media/'.$provider->id.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
