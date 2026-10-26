<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\Apps\ProductBundles;
use App\Services\Commerce\Apps\SocialProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public, read-only data for storefront App store widgets. */
class StorefrontAppsController extends Controller
{
    use ResolvesStorefront;

    public function socialProof(Request $request, SocialProof $proof): JsonResponse
    {
        return response()->json(['data' => $proof->publicFeed($this->currentStore($request))], 200, ['Cache-Control' => 'private, max-age=60'], JSON_UNESCAPED_UNICODE);
    }

    public function bundles(Request $request, ProductBundles $bundles): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1']]);
        $store = $this->currentStore($request);

        return response()->json(['data' => $bundles->publicFor($store, (int) $data['product_id']), 'currency' => $store->currency ?: 'USD'], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }
}
