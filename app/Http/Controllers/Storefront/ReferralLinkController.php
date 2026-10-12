<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Commerce\ReferralAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReferralLinkController extends Controller
{
    use ResolvesStorefront;

    private function product(Request $request, int $product): Product
    {
        return Product::query()->where('store_id', $this->currentStore($request)->id)->where('is_active', true)->whereNotNull('slug')->findOrFail($product);
    }

    public function resolve(Request $request, int $product): JsonResponse
    {
        $product = $this->product($request, $product);

        return response()->json(['data' => ['id' => $product->id, 'slug' => $product->slug]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $product, string $code): RedirectResponse
    {
        abort_if(Validator::make(['code' => $code], ['code' => ReferralAttribution::CODE_RULES])->fails(), 404);
        $product = $this->product($request, $product);
        $query = $request->query();
        $query['ref'] = $code;

        return redirect('/products/'.$product->slug.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, follow');
    }
}
