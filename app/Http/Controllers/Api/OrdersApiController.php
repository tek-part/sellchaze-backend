<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderApiResource;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\User;
use App\Notifications\OrderAssignedToSupplierNotification;
use App\Services\OrderSyncService;
use App\Services\Rbac\UserScope;
use App\Services\Wigpleasure\WigpleasureOrderStatusClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrdersApiController extends Controller
{
    use AppliesMailConfig;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $direction = $request->get('direction');

        $request->validate([
            'source' => ['nullable', 'string', Rule::in(Order::SOURCES)],
        ]);

        if ($direction === 'in') {
            $query = $this->ordersInQuery($user);
        } elseif ($direction === 'out') {
            $query = $this->ordersOutQuery($user);
        } else {
            $query = Order::with(['product', 'user', 'suppliers.supplier'])->latest();
            if (! UserScope::isAdmin($user)) {
                $uid = UserScope::effectiveMerchantUserId($user);
                $query->where(function ($q) use ($uid) {
                    $q->where('user_id', $uid)
                        ->orWhereHas('suppliers', fn ($sq) => $sq->where('customer', $uid))
                        ->orWhereHas('suppliers', fn ($sq) => $sq->where('supplier', $uid));
                });
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('orders.source', $request->get('source'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('orders.code', 'like', '%'.$term.'%')
                    ->orWhere('orders.ref_number', 'like', '%'.$term.'%')
                    ->orWhereHas('product', function ($pq) use ($term) {
                        $pq->where('name', 'like', '%'.$term.'%');
                    });
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $orders = $query->paginate($perPage)->withQueryString();

        return OrderApiResource::collection($orders)->response();
    }

    /**
     * An order is considered "routed" when it has at least one supplier attached
     * (either exclusively via assigned_supplier_id, or fanned-out via order_suppliers).
     * "Orders in" are orders that have NOT been routed yet (still awaiting pickup / routing).
     * "Orders out" are orders that HAVE been routed to at least one supplier.
     */
    private function applyNotRoutedFilter($query)
    {
        return $query->whereNull('assigned_supplier_id')
            ->whereDoesntHave('suppliers');
    }

    private function applyIsRoutedFilter($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('assigned_supplier_id')
                ->orWhereHas('suppliers');
        });
    }

    /**
     * "Orders in" = orders not yet routed to any supplier.
     * Access rules still apply: internal team sees all; supplier sees orders they were routed to
     * (legacy pre-route visibility); merchant sees their own + routed-to orders.
     */
    private function ordersInQuery($user)
    {
        $with = ['suppliers.supplier', 'product', 'quotations', 'assignedSupplier'];
        $base = $this->applyNotRoutedFilter(Order::query()->with($with))->orderByDesc('orders.id');

        if (UserScope::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager'])) {
            return $base;
        }

        if (UserScope::isSupplier($user)) {
            // Supplier should never see unrouted orders — return empty set defensively.
            return Order::query()->whereRaw('1 = 0');
        }

        $ctx = UserScope::effectiveMerchantUserId($user);
        if ($ctx <= 0) {
            return Order::query()->whereRaw('1 = 0');
        }

        return $base->where('orders.user_id', $ctx);
    }

    /**
     * "Orders out" = orders that have been routed to at least one supplier.
     * - Admin/Staff/Manager: all routed orders.
     * - Supplier: routed to them (assigned exclusively OR fanned-out via order_suppliers).
     * - Merchant: orders they authored that are routed, OR where they're the customer.
     */
    private function ordersOutQuery($user)
    {
        $with = ['suppliers.supplier', 'product', 'quotations', 'assignedSupplier'];
        $base = $this->applyIsRoutedFilter(Order::query()->with($with))->orderByDesc('orders.id');

        if (UserScope::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager'])) {
            return $base;
        }

        if (UserScope::isSupplier($user)) {
            $supplierId = (int) $user->id;

            return $base->where(function ($q) use ($supplierId) {
                $q->where('assigned_supplier_id', $supplierId)
                    ->orWhereHas('suppliers', fn ($sq) => $sq->where('supplier', $supplierId));
            });
        }

        $ctx = UserScope::effectiveMerchantUserId($user);
        if ($ctx <= 0) {
            return Order::query()->whereRaw('1 = 0');
        }

        return $base->where(function ($q) use ($ctx) {
            $q->where('orders.user_id', $ctx)
                ->orWhereHas('suppliers', fn ($sq) => $sq->where('customer', $ctx));
        });
    }

    /**
     * Merchant-authored order: create a new order and fan it out to one or more
     * selected suppliers from the merchant's network. Differs from the public
     * Wigpleasure sync endpoint because here the authenticated merchant chooses
     * which suppliers receive the order.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'attributes' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ref_number' => ['nullable', 'string', 'max:64'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'shipping_type' => ['nullable', Rule::in(['customer_direct', 'warehouse'])],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $ownerId = UserScope::effectiveMerchantUserId($user);

        // Only accept suppliers that are actually in the merchant's network.
        $owner = User::find($ownerId) ?? $user;
        $allowedSupplierIds = $owner->suppliersAsMerchant()->pluck('users.id')->map(fn ($x) => (int) $x)->all();
        $supplierIds = array_values(array_unique(array_intersect(
            array_map('intval', $validated['supplier_ids']),
            $allowedSupplierIds
        )));

        if (empty($supplierIds)) {
            return response()->json([
                'message' => __('Please pick at least one supplier from your partners list.'),
            ], 422);
        }

        $order = new Order;
        $order->code = 'ORD-'.$ownerId.'-'.strtoupper(bin2hex(random_bytes(3)));
        $order->source = Order::SOURCE_MERCHANT_DIRECT;
        $order->quantity = (int) $validated['quantity'];
        $order->user_id = $ownerId;
        $order->product_id = (int) $validated['product_id'];
        $order->attributes = serialize($validated['attributes'] ?? []);
        $order->status = 'pending';
        $order->notes = $validated['notes'] ?? '';
        $order->ref_number = $validated['ref_number'] ?? null;
        $order->shipping_address = $validated['shipping_address'] ?? null;
        $order->shipping_type = $validated['shipping_type'] ?? 'customer_direct';
        $order->customer_name = $validated['customer_name'] ?? null;
        $order->customer_email = $validated['customer_email'] ?? null;
        $order->customer_phone = $validated['customer_phone'] ?? null;
        $order->delivery_path = 'customer_direct';
        $order->save();

        foreach ($supplierIds as $sid) {
            OrderSuppliers::create([
                'order_id' => $order->id,
                'customer' => $ownerId,
                'supplier' => $sid,
            ]);

            // In-app notification for each targeted supplier.
            try {
                $supplier = User::find($sid);
                if ($supplier) {
                    $supplier->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'order',
                        'data' => [
                            'type' => 'order',
                            'order_id' => $order->id,
                            'order_code' => $order->code,
                            'merchant_id' => $ownerId,
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'data' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
            ],
        ], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()
            ->with([
                'product',
                'user',
                'suppliers.customer',
                'suppliers.supplier',
                'quotations.supplierUser',
                'quotations.customer',
                'deliveries.shippingCompany',
            ])
            ->withCount('tickets')
            ->where('code', $code)
            ->firstOrFail();

        if (! UserScope::isAdmin($user) && ! $user->hasAnyRole(['Staff', 'Manager'])) {
            $uid = UserScope::effectiveMerchantUserId($user);
            $allowed = (int) $order->user_id === $uid
                || $order->suppliers()->where('customer', $uid)->exists()
                || $order->suppliers()->where('supplier', $uid)->exists();
            if (! $allowed) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        if ($this->supplierBlockedAfterOtherAccepted($user, $order)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->markOrderSeenByAssignedSupplier($user, $order);

        if (Schema::hasTable('payment_gateways') && Schema::hasTable('gateway_transactions')) {
            try {
                app(OrderSyncService::class)->reconcileGatewayForOrder($order);
            } catch (\Throwable $e) {
                Log::warning('OrdersApiController: reconcileGatewayForOrder failed after order show', [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => new OrderApiResource($order)]);
    }

    /**
     * Partial update (status, notes, ref, quantity) for SPA — same access rules as show().
     */
    public function update(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()->where('code', $code)->firstOrFail();

        if (! UserScope::isAdmin($user) && ! $user->hasAnyRole(['Staff', 'Manager'])) {
            $uid = UserScope::effectiveMerchantUserId($user);
            $allowed = (int) $order->user_id === $uid
                || $order->suppliers()->where('customer', $uid)->exists()
                || $order->suppliers()->where('supplier', $uid)->exists();
            if (! $allowed) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        if ($this->supplierBlockedAfterOtherAccepted($user, $order)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($request->has('wigpleasure_store_status') || $request->has('wigpleasure_return_warehouse_id')) {
            if (! UserScope::isAdmin($user)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'ref_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'wigpleasure_store_status' => ['sometimes', 'nullable', 'string', 'max:64', Rule::in(WigpleasureOrderStatusClient::STORE_STATUSES)],
            'wigpleasure_return_warehouse_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        foreach (['status', 'notes', 'ref_number', 'quantity'] as $field) {
            if (array_key_exists($field, $validated)) {
                $order->{$field} = $validated[$field];
            }
        }

        $storeStatusChanged = false;
        if (array_key_exists('wigpleasure_store_status', $validated) && (int) ($order->wigpleasure_order_id ?? 0) > 0) {
            $order->wigpleasure_store_status = $validated['wigpleasure_store_status'];
            $storeStatusChanged = $order->isDirty('wigpleasure_store_status');
        }

        $order->save();

        if (Schema::hasTable('payment_gateways') && Schema::hasTable('gateway_transactions')) {
            try {
                app(OrderSyncService::class)->reconcileGatewayForOrder($order);
            } catch (\Throwable $e) {
                Log::warning('OrdersApiController: reconcileGatewayForOrder failed after order update', [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($storeStatusChanged && UserScope::isAdmin($user) && (int) $order->wigpleasure_order_id > 0) {
            $newStatus = (string) ($order->wigpleasure_store_status ?? '');
            if ($newStatus !== '') {
                $returnWh = $validated['wigpleasure_return_warehouse_id'] ?? null;
                $returnWh = ($returnWh !== null && (int) $returnWh > 0) ? (int) $returnWh : null;
                app(WigpleasureOrderStatusClient::class)->pushStatus((int) $order->wigpleasure_order_id, $newStatus, $returnWh);
            }
        }

        $order->load([
            'product',
            'user',
            'suppliers.customer',
            'suppliers.supplier',
            'quotations.supplierUser',
            'quotations.customer',
            'deliveries.shippingCompany',
        ]);
        $order->loadCount('tickets');

        return response()->json(['data' => new OrderApiResource($order)]);
    }

    /**
     * When the assigned supplier opens the order (API detail), mark their order_suppliers row as seen — parity with web OrderController::show.
     */
    private function markOrderSeenByAssignedSupplier($user, Order $order): void
    {
        $assignment = OrderSuppliers::query()
            ->where('order_id', $order->id)
            ->where('supplier', (int) $user->id)
            ->first();
        if (! $assignment || $assignment->seen) {
            return;
        }
        $assignment->seen = true;
        $assignment->save();
        $order->unsetRelation('suppliers');
        $order->load(['suppliers.customer', 'suppliers.supplier']);
    }

    /**
     * Supplier assigned on the order must not see or edit it once another supplier's quotation was accepted.
     */
    private function supplierBlockedAfterOtherAccepted($user, Order $order): bool
    {
        if (! UserScope::isSupplier($user)) {
            return false;
        }
        if (! $order->relationLoaded('quotations')) {
            $order->load('quotations');
        }
        $accepted = $order->quotations->first(function ($row) {
            return strtolower((string) $row->status) === 'accepted';
        });
        if (! $accepted) {
            return false;
        }

        return (int) $accepted->supplier_user_id !== (int) $user->id;
    }

    /**
     * Bulk delete by order code (matches web OrderController::bulkDestroy rules).
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codes' => ['required', 'array', 'min:1'],
            'codes.*' => ['string', 'max:64'],
        ]);

        $user = $request->user();
        $internalFullAccess = UserScope::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager']);
        $merchantId = UserScope::effectiveMerchantUserId($user);
        $deleted = 0;

        foreach ($validated['codes'] as $code) {
            $order = Order::where('code', $code)->with('suppliers')->first();
            if (! $order) {
                continue;
            }
            if (! $internalFullAccess) {
                $canDelete = $order->suppliers->contains(fn ($row) => (int) $row->customer === (int) $merchantId);
                if (! $canDelete) {
                    continue;
                }
            }
            if ($order->image) {
                $imagePath = public_path('images/orders/uploads/').$order->image;
                $thumbPath = public_path('images/orders/thumbnails/').$order->image;
                if (File::exists($imagePath)) {
                    File::delete($imagePath);
                }
                if (File::exists($thumbPath)) {
                    File::delete($thumbPath);
                }
            }
            $order->delete();
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }

    public function assignSupplier(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['integer'],
        ]);

        $user = $request->user();
        $order = Order::where('code', $code)->firstOrFail();
        $supplierIds = collect($validated['supplier_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        // Match SuppliersApiController admin list: Supplier role or pending supplier registration.
        $validSupplierIds = User::query()
            ->whereIn('id', $supplierIds->all())
            ->where(function ($w) {
                $w->whereHas('roles', fn ($r) => $r->where('name', 'Supplier'))
                    ->orWhere(function ($p) {
                        $p->where('pending_approval', true)
                            ->whereRaw('LOWER(TRIM(COALESCE(registration_role, ""))) = ?', ['supplier']);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
        if ($validSupplierIds->isEmpty()) {
            return response()->json(['message' => 'No valid suppliers selected.'], 422);
        }

        $internalFullAccess = UserScope::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager']);
        $uid = UserScope::effectiveMerchantUserId($user);
        if (! $internalFullAccess && (int) $order->user_id !== (int) $uid) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $customerId = (int) ($order->user_id ?: $uid);
        OrderSuppliers::where('order_id', $order->id)->delete();
        foreach ($validSupplierIds as $supplierId) {
            OrderSuppliers::create([
                'order_id' => $order->id,
                'customer' => $customerId,
                'supplier' => $supplierId,
                'seen' => false,
            ]);
        }

        $order->load('product');
        try {
            $this->applyMailConfigFromSettings();
            foreach ($validSupplierIds as $supplierId) {
                $supplierUser = User::query()->find($supplierId);
                if ($supplierUser) {
                    $supplierUser->notify(new OrderAssignedToSupplierNotification($order->fresh(['product'])));
                }
            }
        } catch (\Throwable $e) {
            Log::error('assignSupplier: failed to notify suppliers', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'supplier_ids' => $validSupplierIds->all(),
                'message' => $e->getMessage(),
            ]);
            report($e);
        }

        return response()->json(['success' => true]);
    }
}
