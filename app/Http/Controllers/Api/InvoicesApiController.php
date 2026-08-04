<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated user's billing invoices (read-only). Invoices are produced
 * by the subscription/billing lifecycle; this exposes the ledger to the account.
 */
class InvoicesApiController extends Controller
{
    /** GET /me/invoices */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $invoices = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($invoices->items())->map(fn (Invoice $i) => $this->present($i))->all(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /** GET /me/invoices/{invoice} */
    public function show(Request $request, int $invoice): JsonResponse
    {
        $model = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->findOrFail($invoice);

        $data = $this->present($model);
        $data['items'] = $model->items->map(fn ($it) => [
            'description' => $it->description,
            'quantity' => $it->quantity,
            'unit_price' => (float) $it->unit_price,
            'total' => (float) $it->total,
        ])->all();

        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function present(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'code' => $i->code,
            'status' => $i->status,
            'amount' => (float) $i->amount,
            'tax_amount' => (float) $i->tax_amount,
            'discount_amount' => (float) $i->discount_amount,
            'total' => (float) $i->total,
            'currency' => $i->currency,
            'due_at' => $i->due_at?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'gateway_payment_url' => $i->gateway_payment_url,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
