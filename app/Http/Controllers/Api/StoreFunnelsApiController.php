<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreFunnel;
use App\Services\PageBuilder\StoreFunnelService;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreFunnelsApiController extends Controller
{
    public function __construct(private readonly StoreFunnelService $funnels) {}

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

        return response()->json(['data' => collect(StoreFunnelService::TEMPLATES)->map(fn ($template, $key) => ['key' => $key] + $template)->values()]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $this->guard($request, $store);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'product_id' => ['required', 'integer'],
            'template_key' => ['required', Rule::in(array_keys(StoreFunnelService::TEMPLATES))],
            'locale' => ['required', Rule::in(LocaleContext::storeSupported($store))],
        ]);
        $funnel = $this->funnels->create($store, $data, $request->user()->id);

        return response()->json(['data' => $this->payload($funnel->load(['page', 'product']))], 201);
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
