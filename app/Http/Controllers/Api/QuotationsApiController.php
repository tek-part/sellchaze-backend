<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderApiResource;
use App\Models\Order;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationsApiController extends Controller
{
    /**
     * Unified Quotations list: shows any order with at least one quotation where the current user is
     * either the customer (merchant) or the supplier on a quotation. Admin sees all.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = UserScope::isAdmin($user);
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $page = max((int) $request->get('page', 1), 1);

        $uid = UserScope::effectiveMerchantUserId($user);

        $base = DB::table('order_quotations as oq')
            ->select('oq.order_id', DB::raw('MAX(oq.id) as max_id'))
            ->join('orders', 'orders.id', '=', 'oq.order_id')
            ->leftJoin('products', 'products.id', '=', 'orders.product_id')
            ->when(! $isAdmin, function ($q) use ($uid) {
                $q->where(function ($w) use ($uid) {
                    $w->where('oq.customer_user_id', $uid)
                        ->orWhere('oq.supplier_user_id', $uid);
                });
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('orders.code', 'like', $term)
                        ->orWhere('orders.ref_number', 'like', $term)
                        ->orWhere('products.name', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('oq.status', $request->string('status')))
            ->groupBy('oq.order_id');

        $groupedQuery = DB::query()->fromSub($base, 'gq')->orderByDesc('max_id');

        $total = (clone $groupedQuery)->count();
        $orderIds = (clone $groupedQuery)->forPage($page, $perPage)->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max((int) ceil($total / $perPage), 1),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        $pos = $orderIds->flip();
        $orders = Order::query()
            ->with(['product', 'quotations.supplierUser', 'quotations.customer', 'suppliers.supplier', 'assignedSupplier'])
            ->whereIn('id', $orderIds)
            ->get()
            ->sortBy(fn ($o) => $pos[$o->id] ?? 99999)
            ->values();

        return response()->json([
            'data' => OrderApiResource::collection($orders),
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }
}
