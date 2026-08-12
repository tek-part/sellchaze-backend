<?php

namespace App\Services\Procurement;

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\ProcurementQuote;
use App\Models\ProcurementRequest;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Validation\ValidationException;

class ProcurementConversationService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function getOrCreate(
        ProcurementRequest $request,
        Organization $supplier,
        int $initiatorUserId,
        ?ProcurementQuote $quote = null,
    ): Conversation {
        $existing = Conversation::query()
            ->where('procurement_request_id', $request->id)
            ->where('supplier_organization_id', $supplier->id)
            ->first();
        if ($existing) {
            if ($request->order && $existing->procurement_order_id === null) {
                $existing->update(['procurement_order_id' => $request->order->id]);
            }
            $existing->participants()->firstOrCreate(['user_id' => $initiatorUserId]);

            return $existing->load(['users:id,name,avatar', 'buyerOrganization:id,name,slug', 'supplierOrganization:id,name,slug']);
        }

        $quote ??= $request->quotes()
            ->where('supplier_organization_id', $supplier->id)
            ->first();
        $initiatorIsSupplier = $supplier->memberships()
            ->where('user_id', $initiatorUserId)
            ->where('status', 'active')
            ->exists();
        $supplierUserId = $quote?->submitted_by_user_id ?: ($initiatorIsSupplier ? $initiatorUserId : null);
        if ($supplierUserId === null) {
            throw ValidationException::withMessages([
                'supplier_organization_id' => 'The supplier must submit a quote before the buyer can start a conversation.',
            ]);
        }

        $conversation = Conversation::query()->firstOrCreate([
            'procurement_request_id' => $request->id,
            'supplier_organization_id' => $supplier->id,
        ], [
            'type' => 'procurement',
            'procurement_order_id' => $request->order?->id,
            'buyer_organization_id' => $request->buyer_organization_id,
        ]);
        if ($request->order && $conversation->procurement_order_id === null) {
            $conversation->update(['procurement_order_id' => $request->order->id]);
        }
        foreach (array_unique([$request->created_by_user_id, $supplierUserId]) as $userId) {
            $conversation->participants()->firstOrCreate(['user_id' => $userId]);
        }

        if ($conversation->wasRecentlyCreated) {
            $this->outbox->record('ProcurementConversationCreated', 'conversation', (string) $conversation->id, [
                'conversation_id' => $conversation->id,
                'procurement_request_id' => $request->id,
                'buyer_organization_id' => $request->buyer_organization_id,
                'supplier_organization_id' => $supplier->id,
            ]);
        }

        return $conversation->load(['users:id,name,avatar', 'buyerOrganization:id,name,slug', 'supplierOrganization:id,name,slug']);
    }
}
