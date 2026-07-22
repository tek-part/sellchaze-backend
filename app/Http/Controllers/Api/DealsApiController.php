<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderQuotationApiResource;
use App\Models\OrderQuotations;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = UserScope::isAdmin($user);
        $direction = $request->get('direction', 'in');
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        if ($direction === 'out') {
            $query = OrderQuotations::with(['order.product', 'supplierUser'])
                ->where('status', 'accepted');
            if (! $isAdmin) {
                $query->where('customer_user_id', UserScope::effectiveMerchantUserId($user));
            }
        } else {
            $query = OrderQuotations::with(['order.product', 'customer'])
                ->where('status', 'accepted');
            if (! $isAdmin) {
                $query->where('supplier_user_id', UserScope::effectiveMerchantUserId($user));
            }
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->whereHas('order', function ($q) use ($term) {
                $q->where('code', 'like', $term)
                    ->orWhere('ref_number', 'like', $term)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $term));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return OrderQuotationApiResource::collection($paginator)->response();
    }
}
