<?php

namespace App\Http\Controllers\Api;

use App\Actions\Quotations\AcceptQuotationAction;
use App\Events\DashboardStatsUpdated;
use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderApiResource;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\User;
use App\Notifications\QuotationCreated;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class OrderQuotationsApiController extends Controller
{
    use AppliesMailConfig;

    /**
     * Supplier submits a quotation for an assigned order (parity with web QuotationController::accept).
     */
    public function store(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()->where('code', $code)->firstOrFail();

        if (! $this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $supplierId = (int) $user->id;
        $assigned = $order->suppliers()->where('supplier', $supplierId)->exists();
        if (! $assigned) {
            return response()->json(['message' => 'You are not assigned to this order as a supplier.'], 403);
        }

        $existing = OrderQuotations::query()
            ->where('order_id', $order->id)
            ->where('supplier_user_id', $supplierId)
            ->first();
        if ($existing) {
            return response()->json(['message' => 'You have already submitted a quotation for this order.'], 422);
        }

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'delivery_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'max:10'],
            'shipping_company' => ['nullable', 'string', 'max:100'],
            'price_includes_shipping' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $quotation = OrderQuotations::query()->create([
            'price' => $validated['price'],
            'delivery_date' => $validated['delivery_date'],
            'notes' => $validated['notes'] ?? null,
            'currency' => 'USD',
            'shipping_company' => $validated['shipping_company'] ?? null,
            'price_includes_shipping' => $request->boolean('price_includes_shipping', true),
            'order_id' => $order->id,
            'supplier_user_id' => $supplierId,
            'customer_user_id' => (int) $order->user_id,
            'status' => 'pending',
        ]);

        event(new DashboardStatsUpdated);

        $customer = User::find($quotation->customer_user_id);
        $supplier = User::find($supplierId);
        $data = [
            'greeting' => $customer
                ? 'Hi '.$customer->name.', you received a new quotation!'
                : 'A new quotation was submitted for order '.$order->code.'.',
            'body' => '<b>'.($supplier?->name ?? 'Supplier').'</b> offered a quotation for your order, check your account for more information.',
            'thanks' => 'Thank you',
            'actionText' => 'Quotation Details',
            'actionURL' => url('/orders/'.$order->code),
            'supplier_id' => $supplierId,
            'order_id' => $order->id,
        ];

        $recipients = collect();
        if ($customer) {
            $recipients->push($customer);
        }
        foreach (User::role('Admin')->get() as $admin) {
            if (! $recipients->contains(fn ($u) => (int) $u->id === (int) $admin->id)) {
                $recipients->push($admin);
            }
        }

        if ($recipients->isNotEmpty()) {
            $this->applyMailConfigFromSettings();
            try {
                Notification::send($recipients, new QuotationCreated($data));
            } catch (\Throwable $e) {
                Log::warning('QuotationCreated notification failed (mail/offline SMTP).', [
                    'order_id' => $order->id,
                    'customer_user_id' => $customer?->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => $this->freshOrderResource($order)], 201);
    }

    /**
     * Supplier updates their own quotation while it is still pending approval.
     */
    public function update(Request $request, string $code, int $quotation): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()->where('code', $code)->firstOrFail();

        if (! $this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $supplierId = (int) $user->id;
        $assigned = $order->suppliers()->where('supplier', $supplierId)->exists();
        if (! $assigned) {
            return response()->json(['message' => 'You are not assigned to this order as a supplier.'], 403);
        }

        $row = OrderQuotations::query()
            ->where('id', $quotation)
            ->where('order_id', $order->id)
            ->where('supplier_user_id', $supplierId)
            ->firstOrFail();

        $status = strtolower(trim((string) $row->status));
        if ($status !== 'pending') {
            return response()->json([
                'message' => 'Quotation can only be edited while it is pending approval.',
            ], 422);
        }

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'delivery_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'max:10'],
            'shipping_company' => ['nullable', 'string', 'max:100'],
            'price_includes_shipping' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $row->update([
            'price' => $validated['price'],
            'delivery_date' => $validated['delivery_date'],
            'notes' => $validated['notes'] ?? null,
            'currency' => 'USD',
            'shipping_company' => $validated['shipping_company'] ?? null,
            'price_includes_shipping' => $request->boolean('price_includes_shipping', true),
        ]);

        event(new DashboardStatsUpdated);

        return response()->json(['data' => $this->freshOrderResource($order)]);
    }

    /**
     * Merchant or admin accepts one quotation. Uses AcceptQuotationAction; sets order status to awaiting_shipping.
     */
    public function accept(Request $request, string $code, int $quotation): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()->where('code', $code)->firstOrFail();

        $orderQuotation = OrderQuotations::query()
            ->where('id', $quotation)
            ->where('order_id', $order->id)
            ->firstOrFail();

        if (! $this->canAcceptQuotation($user, $order, $orderQuotation)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $status = strtolower(trim((string) $orderQuotation->status));

        if ($status === 'rejected') {
            return response()->json([
                'message' => 'This quotation was already rejected (another quotation was accepted for this order).',
            ], 422);
        }

        if (in_array($status, ['accepted', 'deal'], true)) {
            $order->refresh();
            if ($order->status === 'deal') {
                $order->status = 'awaiting_shipping';
                $order->save();
            }

            return response()->json(['data' => $this->freshOrderResource($order)]);
        }

        $accepted = app(AcceptQuotationAction::class)($orderQuotation->id);
        if (! $accepted) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        $order->status = 'awaiting_shipping';
        $order->save();

        return response()->json(['data' => $this->freshOrderResource($order)]);
    }

    /**
     * Supplier adds tracking on an accepted quotation.
     */
    public function updateTracking(Request $request, string $code, int $quotation): JsonResponse
    {
        $user = $request->user();
        $order = Order::query()->where('code', $code)->firstOrFail();

        $row = OrderQuotations::query()
            ->where('id', $quotation)
            ->where('order_id', $order->id)
            ->where('supplier_user_id', (int) $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:255'],
        ]);

        $status = strtolower((string) $row->status);
        if (! in_array($status, ['accepted', 'deal'], true)) {
            return response()->json(['message' => 'Only accepted quotations can be updated with tracking.'], 422);
        }

        $row->update([
            'tracking_number' => $validated['tracking_number'],
            'shipped_at' => now(),
        ]);

        $order->refresh();
        $order->status = 'shipped';
        $order->save();

        return response()->json(['data' => $this->freshOrderResource($order)]);
    }

    private function canAccessOrder(?User $user, Order $order): bool
    {
        if (! $user) {
            return false;
        }
        if (UserScope::isAdmin($user)) {
            return true;
        }
        $uid = UserScope::effectiveMerchantUserId($user);
        if ((int) $order->user_id === $uid) {
            return true;
        }
        if ($order->suppliers()->where('customer', $uid)->exists()) {
            return true;
        }
        if ($order->suppliers()->where('supplier', (int) $user->id)->exists()) {
            return true;
        }

        return false;
    }

    private function canAcceptQuotation(User $user, Order $order, OrderQuotations $q): bool
    {
        if (UserScope::isAdmin($user)) {
            return true;
        }
        $uid = UserScope::effectiveMerchantUserId($user);

        return (int) $order->user_id === $uid;
    }

    private function freshOrderResource(Order $order): OrderApiResource
    {
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

        return new OrderApiResource($order);
    }
}
