<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-pair ledger between a Merchant (customer) and a Supplier.
 *
 * Balance = Σ(order_charge amounts) − Σ(manual_payment amounts).
 * Positive balance = merchant owes supplier. Zero/negative = settled.
 */
class LedgerApiController extends Controller
{
    public function show(Request $request, int $partnerId): JsonResponse
    {
        $me = $request->user();
        $partner = User::query()->findOrFail($partnerId);

        [$supplierId, $customerId] = $this->resolvePair($me, $partner);

        $rows = Transaction::query()
            ->where('supplier_user_id', $supplierId)
            ->where('customer_user_id', $customerId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $charges = (int) $rows->where('type', Transaction::TYPE_ORDER_CHARGE)->sum('amount');
        $payments = (int) $rows->where('type', Transaction::TYPE_MANUAL_PAYMENT)->sum('amount');
        $balance = $charges - $payments;

        return response()->json([
            'partner' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'email' => $partner->email,
            ],
            'supplier_user_id' => $supplierId,
            'customer_user_id' => $customerId,
            'totals' => [
                'charges' => $charges,
                'payments' => $payments,
                'balance' => $balance,
            ],
            'entries' => $rows->map(fn (Transaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (int) $t->amount,
                'orders' => $t->orders,
                'transfer_method' => $t->transfer_method,
                'notes' => $t->notes,
                'quotation_id' => $t->quotation_id,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function addPayment(Request $request, int $partnerId): JsonResponse
    {
        $me = $request->user();
        $partner = User::query()->findOrFail($partnerId);
        [$supplierId, $customerId] = $this->resolvePair($me, $partner);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'transfer_method' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ]);

        $tx = Transaction::create([
            'type' => Transaction::TYPE_MANUAL_PAYMENT,
            'supplier_user_id' => $supplierId,
            'customer_user_id' => $customerId,
            'amount' => (int) $validated['amount'],
            'transfer_method' => $validated['transfer_method'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => [
            'id' => $tx->id,
            'type' => $tx->type,
            'amount' => (int) $tx->amount,
        ]], 201);
    }

    /**
     * Given the current user and a partner user, decide who is supplier and who is customer (merchant).
     */
    private function resolvePair(User $me, User $partner): array
    {
        if ($me->isSupplier()) {
            return [(int) $me->id, (int) $partner->id];
        }
        if ($partner->isSupplier()) {
            return [(int) $partner->id, (int) $me->id];
        }
        // Default: treat current user as merchant/customer.
        return [(int) $partner->id, (int) $me->id];
    }
}
