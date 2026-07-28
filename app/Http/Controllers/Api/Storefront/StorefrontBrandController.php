<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontBrandResource;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontBrandController extends Controller
{
    use ResolvesStorefront;

    public function __construct(private readonly StorefrontService $storefront) {}

    /** GET /storefront/brands — the store's brands (logos + counts). */
    public function index(Request $request): JsonResponse
    {
        $this->currentStore($request);
        $limit = min(max((int) $request->get('limit', 24), 1), 60);

        return response()->json([
            'data' => StorefrontBrandResource::collection($this->storefront->brands($limit)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
