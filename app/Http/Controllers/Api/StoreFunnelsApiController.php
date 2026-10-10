<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreFunnel;
use App\Services\PageBuilder\FunnelContentGenerator;
use App\Services\PageBuilder\StoreFunnelService;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class StoreFunnelsApiController extends Controller
{
    public function __construct(private readonly StoreFunnelService $funnels, private readonly FunnelContentGenerator $generator) {}

    private function guard(Request $request, Store $store): void
    {
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.pages.manage') || $user->can('stores-edit')), 403);
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $this->guard($request, $store);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'in:draft,published,scheduled'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = StoreFunnel::query()->with(['page', 'product'])
            ->whereHas('page', function ($query) use ($data) {
                if (! empty($data['search'])) {
                    $query->where('title', 'like', '%'.$data['search'].'%');
                }
                if (! empty($data['status'])) {
                    $query->where('status', $data['status']);
                }
            })->latest('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $rows->getCollection()->map(fn ($funnel) => $this->payload($funnel)), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function templates(Request $request, Store $store): JsonResponse
    {
        $this->guard($request, $store);

        return response()->json(['data' => collect(StoreFunnelService::TEMPLATES)->map(fn ($template, $key) => ['key' => $key] + $template)->values(), 'ai' => ['available' => $this->generator->available()]]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $this->guard($request, $store);
        $data = $this->validateInput($request, $store);
        $funnel = $this->funnels->create($store, $data, $request->user()->id);

        return response()->json(['data' => $this->payload($funnel->load(['page', 'product']))], 201);
    }

    private function validateInput(Request $request, Store $store, bool $ai = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'product_id' => ['required', 'integer'],
            'template_key' => ['required', Rule::in(array_keys(StoreFunnelService::TEMPLATES))],
            'locale' => ['required', Rule::in(LocaleContext::storeSupported($store))],
        ] + ($ai ? [
            'generation' => ['required', 'array:language,dialect,product_name,description'],
            'generation.language' => ['required', 'string', 'max:80'],
            'generation.dialect' => ['required', 'string', 'max:120'],
            'generation.product_name' => ['required', 'string', 'max:255'],
            'generation.description' => ['required', 'string', 'min:20', 'max:6000'],
        ] : []));
    }

    public function generate(Request $request, Store $store): JsonResponse
    {
        $this->guard($request, $store);
        $data = $this->validateInput($request, $store, true);
        $this->funnels->validateProduct($store, $data);
        abort_unless($this->generator->available(), 503, 'AI generation is not configured. Create the funnel manually for now.');
        // Owners, employees and admin routes share the same store budget.
        $key = 'funnel-ai:'.$store->id;
        $lock = Cache::lock($key.':lock', 90);
        abort_unless($lock->get(), 429, 'A funnel is already being generated for this store.');
        try {
            if (RateLimiter::tooManyAttempts($key, max(1, (int) config('services.funnel_ai.per_day', 20)))) {
                return response()->json(['message' => 'The daily AI generation limit has been reached.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
            // Count provider attempts, including failures, to bound retry spending.
            RateLimiter::hit($key, 86400);
            $copy = $this->generator->generate($data['generation']);
            $funnel = $this->funnels->create($store, $data, $request->user()->id, $copy);

            return response()->json(['data' => $this->payload($funnel->load(['page', 'product']))], 201);
        } finally {
            $lock->release();
        }
    }

    public function duplicate(Request $request, Store $store, int $funnel): JsonResponse
    {
        $this->guard($request, $store);
        $source = StoreFunnel::query()->with('page')->findOrFail($funnel);
        $copy = $this->funnels->duplicate($store, $source, $request->user()->id);

        return response()->json(['data' => $this->payload($copy->load(['page', 'product']))], 201);
    }

    private function payload(StoreFunnel $funnel): array
    {
        return [
            'id' => $funnel->id, 'page_id' => $funnel->store_page_id, 'template_key' => $funnel->template_key,
            'title' => $funnel->page->title, 'slug' => $funnel->page->slug, 'status' => $funnel->page->status,
            'locale' => $funnel->page->locale, 'product' => $funnel->product?->only(['id', 'name', 'slug']),
            'public_path' => '/pages/'.($funnel->page->published_slug ?: $funnel->page->slug),
            'created_at' => $funnel->created_at,
        ];
    }
}
