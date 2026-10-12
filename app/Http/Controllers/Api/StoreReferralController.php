<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreReferralController extends Controller
{
    public function products(Request $request, Store $store, StorefrontUrlGenerator $urls): JsonResponse
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = Product::query()->where('store_id', $store->id)->where('is_active', true)->whereNotNull('slug');
        $q = trim($data['q'] ?? '');
        if ($q !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(function ($builder) use ($pattern) {
                foreach (['name', 'sku', 'translations->en->name', 'translations->ar->name'] as $column) {
                    $builder->orWhereRaw($builder->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $products = $query->orderByDesc('id')->paginate(20, ['id', 'name', 'translations', 'slug']);

        return response()->json(['data' => $products->getCollection()->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->translated('name', $store->default_locale ?: 'en')]),
            'storefront_url' => $urls->publicUrl($store), 'meta' => ['current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total()]], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }
}
