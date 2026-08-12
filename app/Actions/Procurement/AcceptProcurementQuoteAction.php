<?php

namespace App\Actions\Procurement;

use App\Models\ProcurementQuote;
use App\Models\ProcurementOrder;
use App\Models\ProcurementAuditEntry;
use App\Services\Outbox\OutboxRecorder;
use App\Services\Procurement\ProcurementConversationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptProcurementQuoteAction
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly ProcurementConversationService $conversations,
    ) {}

    public function execute(string $requestId, string $quoteId, int $actorUserId): ProcurementQuote
    {
        return DB::transaction(function () use ($requestId, $quoteId, $actorUserId): ProcurementQuote {
            $quote = ProcurementQuote::query()
                ->where('procurement_request_id', $requestId)
                ->lockForUpdate()
                ->findOrFail($quoteId);
            $procurementRequest = $quote->procurementRequest()->lockForUpdate()->firstOrFail();

            if ($procurementRequest->status !== 'published' || $quote->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'quote' => 'Only a submitted quote on a published request can be accepted.',
                ]);
            }

            ProcurementQuote::query()
                ->where('procurement_request_id', $procurementRequest->id)
                ->where('id', '!=', $quote->id)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected', 'updated_at' => now()]);

            $quote->update(['status' => 'accepted']);
            $procurementRequest->update([
                'status' => 'awarded',
                'awarded_quote_id' => $quote->id,
            ]);

            $order = ProcurementOrder::query()->create([
                'order_number' => 'PO-'.strtoupper(substr(str_replace('-', '', $quote->id), 0, 12)),
                'procurement_request_id' => $procurementRequest->id,
                'procurement_quote_id' => $quote->id,
                'buyer_organization_id' => $procurementRequest->buyer_organization_id,
                'supplier_organization_id' => $quote->supplier_organization_id,
                'store_id' => $procurementRequest->store_id,
                'created_by_user_id' => $actorUserId,
                'title' => $procurementRequest->title,
                'quantity' => $procurementRequest->quantity,
                'unit' => $procurementRequest->unit,
                'total' => $quote->amount,
                'currency' => $quote->currency,
                'status' => 'confirmed',
                'expected_delivery_at' => $quote->lead_time_days === null
                    ? null
                    : now()->addDays($quote->lead_time_days),
                'metadata' => [
                    'request_description' => $procurementRequest->description,
                    'quote_notes' => $quote->notes,
                ],
            ]);
            ProcurementAuditEntry::query()->create([
                'procurement_request_id' => $procurementRequest->id,
                'procurement_quote_id' => $quote->id,
                'procurement_order_id' => $order->id,
                'actor_user_id' => $actorUserId,
                'event' => 'quote_accepted',
                'from_status' => 'published',
                'to_status' => 'awarded',
                'payload' => ['order_number' => $order->order_number],
            ]);

            $this->outbox->record('ProcurementQuoteAccepted', 'procurement_request', $procurementRequest->id, [
                'procurement_request_id' => $procurementRequest->id,
                'quote_id' => $quote->id,
                'buyer_organization_id' => $procurementRequest->buyer_organization_id,
                'supplier_organization_id' => $quote->supplier_organization_id,
                'procurement_order_id' => $order->id,
            ]);
            $this->outbox->record('ProcurementOrderCreated', 'procurement_order', $order->id, [
                'procurement_order_id' => $order->id,
                'order_number' => $order->order_number,
                'buyer_organization_id' => $order->buyer_organization_id,
                'supplier_organization_id' => $order->supplier_organization_id,
            ]);
            $procurementRequest->setRelation('order', $order);
            $this->conversations->getOrCreate(
                $procurementRequest,
                $quote->supplierOrganization()->firstOrFail(),
                $actorUserId,
                $quote,
            );

            return $quote->refresh()->load('procurementRequest.order');
        });
    }
}
