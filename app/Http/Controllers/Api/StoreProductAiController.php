<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\StoreCatalog\ProductContentGenerator;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/** Uses the same store scope and create/edit grants as the product editor. */
class StoreProductAiController extends Controller
{
    public function __construct(private readonly ProductContentGenerator $generator) {}

    public function status(Request $request, Store $store, ?int $product = null): JsonResponse
    {
        $this->product($store, $product);

        return response()->json(['available' => $this->generator->available()], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function generate(Request $request, Store $store, ?int $product = null): JsonResponse
    {
        $this->product($store, $product);
        $data = $request->validate([
            'locale' => ['required', Rule::in(LocaleContext::storeSupported($store))],
            'parts' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'parts.*' => ['required', 'distinct', Rule::in(ProductContentGenerator::PARTS)],
            'counts' => ['required', 'array:features,faqs,reviews'],
            'counts.features' => ['required', 'integer', 'min:0', 'max:10'],
            'counts.faqs' => ['required', 'integer', 'min:0', 'max:10'],
            'counts.reviews' => ['required', 'integer', 'min:0', 'max:10'],
            'language' => ['required', 'string', 'max:80'], 'dialect' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'], 'description' => ['required', 'string', 'min:20', 'max:6000'],
            'review_source' => ['nullable', 'string', 'max:4000'],
        ]);
        abort_unless($this->generator->available(), 503, 'Product AI generation is not configured. You can continue editing manually.');
        $key = 'product-ai:'.$store->id;
        $lock = Cache::lock($key.':lock', 90);
        abort_unless($lock->get(), 429, 'Product content is already being generated for this store.');
        try {
            if (RateLimiter::tooManyAttempts($key, max(1, (int) config('services.product_ai.per_day', 20)))) {
                return response()->json(['message' => 'The daily product AI generation limit has been reached.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 86400);
            $copy = $this->generator->generate($data);

            return response()->json(['data' => $copy, 'locale' => $data['locale'], 'parts' => $data['parts'], 'review_provenance' => 'ai_summary_of_supplied_feedback'], 200, ['Cache-Control' => 'private, no-store']);
        } finally {
            $lock->release();
        }
    }

    private function product(Store $store, ?int $product): void
    {
        if ($product !== null) {
            Product::query()->where('store_id', $store->id)->findOrFail($product);
        }
    }
}
