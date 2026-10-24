<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Services\Commerce\StoreCustomerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreCustomersApiController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = StoreCustomer::query()->where('store_id', $store->id)
            ->select(['id', 'store_id', 'name', 'email', 'phone', 'is_active', 'created_at'])
            ->withCount('orders');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->get('status') === 'active');
        }
        $customers = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['data' => $customers->items(), 'meta' => [
            'current_page' => $customers->currentPage(), 'last_page' => $customers->lastPage(),
            'total' => $customers->total(), 'per_page' => $customers->perPage(),
        ]]);
    }

    /** Customers built from orders (guests included), with Shopify-style segments and filters. */
    public function directory(Request $request, Store $store, StoreCustomerDirectory $directory): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'orders_min' => ['nullable', 'integer', 'min:0'], 'orders_max' => ['nullable', 'integer', 'min:0'],
            'spent_min' => ['nullable', 'numeric', 'min:0'], 'spent_max' => ['nullable', 'numeric', 'min:0'],
            'last_order_from' => ['nullable', 'date'], 'last_order_to' => ['nullable', 'date'], 'first_order_from' => ['nullable', 'date'],
            'account' => ['nullable', 'in:registered,guest'],
            'sort_by' => ['nullable', 'in:'.implode(',', StoreCustomerDirectory::SORTS)], 'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $directory->paginate($store, $filters, (int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
        ]]);
    }

    public function show(Request $request, Store $store, int $customer): JsonResponse
    {
        $model = StoreCustomer::query()->where('store_id', $store->id)
            ->select(['id', 'store_id', 'name', 'email', 'phone', 'is_active', 'created_at', 'last_login_at', 'email_verified_at'])
            ->withCount('orders')->findOrFail($customer);
        $orders = $model->orders()->where('store_id', $store->id)
            ->select(['id', 'order_number', 'status', 'payment_status', 'currency', 'grand_total', 'placed_at'])
            ->orderByDesc('id')->paginate(10);

        return response()->json(['data' => $model, 'orders' => [
            'data' => $orders->items(), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]]);
    }
}
