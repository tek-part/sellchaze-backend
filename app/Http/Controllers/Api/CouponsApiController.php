<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6D: merchant coupon management. Bound under `store.scope`, so the
 * CurrentStore tenant is set and every Coupon query/create is auto-isolated to
 * the owner's store (ownership already asserted by the middleware/StorePolicy).
 */
class CouponsApiController extends Controller
{
    /** GET /stores/{store}/coupons */
    public function index(Request $request, Store $store): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $paginator = Coupon::query()
            ->withCount('usages')
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%'.strtoupper((string) $request->get('search')).'%'))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => CouponResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** POST /stores/{store}/coupons */
    public function store(StoreCouponRequest $request, Store $store): JsonResponse
    {
        $coupon = Coupon::create($request->validated());

        return response()->json(['data' => new CouponResource($coupon)], 201);
    }

    /** GET /stores/{store}/coupons/{coupon} */
    public function show(Request $request, Store $store, int $coupon): JsonResponse
    {
        return response()->json(['data' => new CouponResource($this->find($coupon)->loadCount('usages'))]);
    }

    /** PUT /stores/{store}/coupons/{coupon} */
    public function update(StoreCouponRequest $request, Store $store, int $coupon): JsonResponse
    {
        $model = $this->find($coupon);
        $model->update($request->validated());

        return response()->json(['data' => new CouponResource($model)]);
    }

    /** DELETE /stores/{store}/coupons/{coupon} */
    public function destroy(Request $request, Store $store, int $coupon): JsonResponse
    {
        $this->find($coupon)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function find(int $id): Coupon
    {
        $coupon = Coupon::query()->find($id); // StoreScope-isolated to the current store
        abort_if($coupon === null, 404, 'Coupon not found.');

        return $coupon;
    }
}
