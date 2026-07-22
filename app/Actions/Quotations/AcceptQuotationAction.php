<?php

namespace App\Actions\Quotations;

use App\Events\DashboardStatsUpdated;
use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\QuotationApproved;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Handles customer accepting/confirming a supplier's quotation.
 * Rejects other quotations for the same order and marks the selected one as accepted.
 */
class AcceptQuotationAction
{
    use AppliesMailConfig;

    /**
     * Accept a quotation by id (decrypted). Customer confirms supplier's quote.
     *
     * @param  int  $quotationId  Decrypted OrderQuotations id
     * @return OrderQuotations|null The accepted quotation or null if not found
     */
    public function __invoke(int $quotationId): ?OrderQuotations
    {
        $orderQuotation = OrderQuotations::find($quotationId);

        if (! $orderQuotation) {
            return null;
        }

        OrderQuotations::where('id', '<>', $orderQuotation->id)
            ->where('order_id', $orderQuotation->order_id)
            ->where('customer_user_id', $orderQuotation->customer_user_id)
            ->update(['status' => 'rejected']);

        $orderQuotation->status = 'accepted';
        $orderQuotation->save();

        $order = Order::find($orderQuotation->order_id);

        if ($order && (int) $orderQuotation->supplier_user_id > 0) {
            $order->assigned_supplier_id = (int) $orderQuotation->supplier_user_id;
            $order->save();
        }

        // Ledger: create an order_charge transaction when a quotation is accepted (idempotent).
        if ((int) $orderQuotation->supplier_user_id > 0 && (int) $orderQuotation->customer_user_id > 0) {
            Transaction::firstOrCreate(
                [
                    'type' => Transaction::TYPE_ORDER_CHARGE,
                    'quotation_id' => $orderQuotation->id,
                ],
                [
                    'supplier_user_id' => (int) $orderQuotation->supplier_user_id,
                    'customer_user_id' => (int) $orderQuotation->customer_user_id,
                    'amount' => (int) round((float) $orderQuotation->price),
                    'orders' => (string) ($order?->code ?? $orderQuotation->order_id),
                    'transfer_method' => null,
                    'notes' => 'Auto-generated from accepted quotation.',
                ]
            );
        }

        event(new DashboardStatsUpdated);
        $supplier = User::find($orderQuotation->supplier_user_id);
        $customer = User::find($orderQuotation->customer_user_id);

        if ($supplier && $order) {
            $dealData = [
                'supplier' => $supplier->name,
                'customer' => $customer ? $customer->name : '',
                'price' => $orderQuotation->price,
                'refnum' => $order->code ?? (string) $orderQuotation->order_id,
                'order_id' => $order->id,
                'order_code' => $order->code,
                'quotation_id' => $orderQuotation->id,
            ];
            $this->applyMailConfigFromSettings();
            try {
                Notification::send($supplier, new QuotationApproved($dealData));
            } catch (\Throwable $e) {
                Log::warning('QuotationApproved notification failed (mail/offline SMTP).', [
                    'quotation_id' => $orderQuotation->id,
                    'supplier_user_id' => $supplier->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $orderQuotation;
    }
}
