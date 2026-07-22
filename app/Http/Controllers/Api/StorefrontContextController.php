<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 resolution endpoint. Given the request Host (via ResolveStoreFromHost),
 * returns the resolved store context — or 404 when the host maps to no store.
 * This proves host->store resolution without rendering a storefront (Phase 3).
 */
class StorefrontContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');

        if (! $store instanceof Store) {
            return response()->json(['message' => 'No store for this host.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'currency' => $store->currency,
                'host' => $request->getHost(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
