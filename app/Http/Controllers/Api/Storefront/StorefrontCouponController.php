<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontCouponResource;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCouponController extends Controller
{
    use ResolvesStorefront;

    public function __construct(private readonly StorefrontService $storefront) {}

    /** GET /storefront/coupons — active, currently-valid coupons (public-safe subset). */
    public function index(Request $request): JsonResponse
    {
        $this->currentStore($request);
        $limit = min(max((int) $request->get('limit', 8), 1), 20);

        return response()->json([
            'data' => StorefrontCouponResource::collection($this->storefront->coupons($limit)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
