<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\IdempotencyRecord;
use App\Models\Organization;
use App\Models\ProcurementRequest;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Organization} */
    private function company(string $slug): array
    {
        $user = User::factory()->create([
            'email' => $slug.'@example.com',
            'is_active' => true,
            'pending_approval' => false,
        ]);
        $organization = Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function asUser(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    public function test_company_discovery_excludes_own_tenants_and_supports_search(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-directory');
        [, $supplierOrganization] = $this->company('alexandria-factory');
        $supplierOrganization->update(['name' => 'Alexandria Glass Factory', 'type' => 'factory']);

        $this->asUser($buyer)->getJson('/api/v2/directory/organizations?q=Glass&type=factory')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supplierOrganization->id)
            ->assertJsonMissing(['id' => $buyerOrganization->id]);
    }

    public function test_targeted_rfq_is_visible_and_quotable_only_by_the_invited_supplier(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-targeted');
        [$invited, $invitedOrganization] = $this->company('supplier-invited');
        [$outsider, $outsiderOrganization] = $this->company('supplier-outsider');

        $requestId = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'target_supplier_organization_id' => $invitedOrganization->id,
            'title' => 'Private packaging requirement',
            'quantity' => 500,
            'unit' => 'box',
            'status' => 'published',
        ])->assertCreated()->json('data.id');

        $this->asUser($invited)->getJson('/api/v2/procurement/requests')
            ->assertOk()->assertJsonPath('data.0.id', $requestId);
        $this->asUser($outsider)->getJson('/api/v2/procurement/requests')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($outsider)->getJson("/api/v2/procurement/requests/{$requestId}")->assertNotFound();
        $this->asUser($outsider)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $outsiderOrganization->id,
            'amount' => 100,
        ])->assertNotFound();
        $this->asUser($invited)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $invitedOrganization->id,
            'amount' => 120,
        ])->assertCreated();
    }

    public function test_company_can_publish_rfq_and_other_company_can_submit_once(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer');
        [$supplier, $supplierOrganization] = $this->company('supplier');

        $response = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Ten thousand glass bottles',
            'quantity' => 10000,
            'unit' => 'piece',
            'currency' => 'EGP',
            'status' => 'published',
            'response_deadline' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.buyer_organization_id', $buyerOrganization->id)
            ->assertJsonPath('data.status', 'published');

        $requestId = $response->json('data.id');
        $payload = [
            'supplier_organization_id' => $supplierOrganization->id,
            'amount' => 82000,
            'lead_time_days' => 14,
        ];
        $this->asUser($supplier)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", $payload)
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EGP');

        $this->asUser($supplier)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", $payload)
            ->assertUnprocessable();

        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementRequestCreated']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementQuoteSubmitted']);
    }

    public function test_accepting_quote_is_atomic_and_rejects_competing_quotes(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-accept');
        [$supplierA, $organizationA] = $this->company('supplier-a');
        [$supplierB, $organizationB] = $this->company('supplier-b');

        $requestId = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Packaging cartons',
            'quantity' => 500,
            'unit' => 'box',
            'status' => 'published',
        ])->json('data.id');

        $quoteA = $this->asUser($supplierA)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $organizationA->id,
            'amount' => 15000,
        ])->json('data.id');
        $quoteB = $this->asUser($supplierB)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $organizationB->id,
            'amount' => 14000,
        ])->json('data.id');

        $this->asUser($buyer)
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes/{$quoteB}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('procurement_requests', [
            'id' => $requestId, 'status' => 'awarded', 'awarded_quote_id' => $quoteB,
        ]);
        $this->assertDatabaseHas('procurement_quotes', ['id' => $quoteA, 'status' => 'rejected']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementQuoteAccepted']);
        $this->assertDatabaseHas('procurement_orders', [
            'procurement_request_id' => $requestId,
            'procurement_quote_id' => $quoteB,
            'buyer_organization_id' => $buyerOrganization->id,
            'supplier_organization_id' => $organizationB->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementOrderCreated']);
    }

    public function test_draft_request_is_hidden_and_supplier_cannot_accept_quote(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-private');
        [$supplier] = $this->company('supplier-private');
        $draft = ProcurementRequest::query()->create([
            'buyer_organization_id' => $buyerOrganization->id,
            'created_by_user_id' => $buyer->id,
            'title' => 'Private draft',
            'quantity' => 1,
            'unit' => 'unit',
            'currency' => 'EGP',
            'status' => 'draft',
        ]);

        $this->asUser($supplier)->getJson("/api/v2/procurement/requests/{$draft->id}")
            ->assertNotFound();
        $this->asUser($supplier)->postJson("/api/v2/procurement/requests/{$draft->id}/quotes", [
            'supplier_organization_id' => $buyerOrganization->id,
            'amount' => 1,
        ])->assertUnprocessable();
    }

    public function test_competing_quotes_are_confidential_and_remain_visible_to_their_supplier_after_award(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-confidential');
        [$supplierA, $organizationA] = $this->company('supplier-confidential-a');
        [$supplierB, $organizationB] = $this->company('supplier-confidential-b');
        [$observer] = $this->company('supplier-observer');

        $requestId = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Confidential tender',
            'quantity' => 20,
            'unit' => 'pallet',
            'status' => 'published',
        ])->json('data.id');

        $quoteA = $this->asUser($supplierA)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $organizationA->id,
            'amount' => 10000,
        ])->json('data.id');
        $quoteB = $this->asUser($supplierB)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $organizationB->id,
            'amount' => 9000,
        ])->json('data.id');

        $this->asUser($observer)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.quotes');
        $this->asUser($supplierA)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quotes')
            ->assertJsonPath('data.quotes.0.id', $quoteA);
        $this->asUser($buyer)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.quotes');

        $this->asUser($buyer)
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes/{$quoteB}/accept")
            ->assertOk();

        $this->asUser($supplierA)->getJson('/api/v2/procurement/requests?per_page=50')
            ->assertOk()
            ->assertJsonFragment(['id' => $requestId]);
        $this->asUser($supplierA)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quotes')
            ->assertJsonPath('data.quotes.0.id', $quoteA);
        $this->asUser($observer)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertNotFound();
    }

    public function test_awarded_request_becomes_order_with_party_scoped_lifecycle(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-order');
        [$supplier, $supplierOrganization] = $this->company('supplier-order');
        [$observer] = $this->company('observer-order');

        $requestId = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Order bridge item',
            'quantity' => 30,
            'unit' => 'case',
            'status' => 'published',
        ])->json('data.id');
        $quoteId = $this->asUser($supplier)->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
            'supplier_organization_id' => $supplierOrganization->id,
            'amount' => 4500,
            'lead_time_days' => 4,
        ])->json('data.id');

        $accept = $this->asUser($buyer)
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes/{$quoteId}/accept")
            ->assertOk()
            ->assertJsonPath('data.procurement_request.order.status', 'confirmed');
        $orderId = $accept->json('data.procurement_request.order.id');

        $this->assertDatabaseHas('conversations', [
            'type' => 'procurement',
            'procurement_request_id' => $requestId,
            'procurement_order_id' => $orderId,
            'buyer_organization_id' => $buyerOrganization->id,
            'supplier_organization_id' => $supplierOrganization->id,
        ]);
        $conversationId = (int) Conversation::query()
            ->where('procurement_request_id', $requestId)
            ->valueOrFail('id');
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $buyer->id]);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversationId, 'user_id' => $supplier->id]);

        $this->asUser($observer)->getJson("/api/v2/procurement/orders/{$orderId}")->assertNotFound();
        $this->asUser($supplier)->patchJson("/api/v2/procurement/orders/{$orderId}", [
            'status' => 'in_fulfillment',
        ])->assertOk();
        $this->asUser($buyer)->patchJson("/api/v2/procurement/orders/{$orderId}", [
            'status' => 'shipped',
        ])->assertForbidden();
        $this->asUser($supplier)->patchJson("/api/v2/procurement/orders/{$orderId}", [
            'status' => 'shipped',
        ])->assertOk();
        $this->asUser($supplier)->patchJson("/api/v2/procurement/orders/{$orderId}", [
            'status' => 'completed',
        ])->assertForbidden();
        $this->asUser($buyer)->patchJson("/api/v2/procurement/orders/{$orderId}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->asUser($supplier)->getJson('/api/v2/procurement/orders')
            ->assertOk()
            ->assertJsonFragment(['id' => $orderId, 'status' => 'completed']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementOrderStatusChanged']);
    }

    public function test_supplier_can_open_company_context_conversation_without_exposing_it_to_outsiders(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-chat');
        [$supplier, $supplierOrganization] = $this->company('supplier-chat');
        [$observer] = $this->company('observer-chat');
        $requestId = $this->asUser($buyer)->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Conversation RFQ',
            'quantity' => 5,
            'unit' => 'unit',
            'status' => 'published',
        ])->json('data.id');

        $created = $this->asUser($supplier)
            ->postJson("/api/v2/procurement/requests/{$requestId}/conversation", [
                'supplier_organization_id' => $supplierOrganization->id,
            ])->assertCreated()
            ->assertJsonPath('data.type', 'procurement')
            ->assertJsonPath('data.buyer_organization.id', $buyerOrganization->id)
            ->assertJsonPath('data.supplier_organization.id', $supplierOrganization->id);
        $conversationId = $created->json('data.id');

        $this->asUser($buyer)->postJson("/api/v2/procurement/requests/{$requestId}/conversation", [
            'supplier_organization_id' => $supplierOrganization->id,
        ])->assertOk()->assertJsonPath('data.id', $conversationId);
        $this->asUser($observer)->postJson("/api/v2/procurement/requests/{$requestId}/conversation", [
            'supplier_organization_id' => $supplierOrganization->id,
        ])->assertNotFound();

        $this->asUser($buyer)->getJson('/api/v1/chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.context.procurement_request_id', $requestId)
            ->assertJsonPath('data.0.context.supplier_organization.id', $supplierOrganization->id);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementConversationCreated']);
    }

    public function test_repeating_an_rfq_with_the_same_idempotency_key_replays_the_original_result(): void
    {
        [$buyer, $organization] = $this->company('buyer-idempotent');
        $payload = [
            'organization_id' => $organization->id,
            'title' => 'Idempotent packaging RFQ',
            'quantity' => 40,
            'unit' => 'box',
            'status' => 'published',
        ];

        $first = $this->asUser($buyer)
            ->withHeader('Idempotency-Key', 'rfq-test-0001')
            ->postJson('/api/v2/procurement/requests', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false');

        $second = $this->asUser($buyer)
            ->withHeader('Idempotency-Key', 'rfq-test-0001')
            ->postJson('/api/v2/procurement/requests', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('procurement_requests', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementRequestCreated']);
    }

    public function test_reusing_an_idempotency_key_with_a_different_payload_is_rejected(): void
    {
        [$buyer, $organization] = $this->company('buyer-idempotency-conflict');
        $payload = [
            'organization_id' => $organization->id,
            'title' => 'Original RFQ',
            'quantity' => 5,
            'unit' => 'unit',
            'status' => 'published',
        ];

        $this->asUser($buyer)->withHeader('Idempotency-Key', 'rfq-test-0002')
            ->postJson('/api/v2/procurement/requests', $payload)->assertCreated();

        $this->asUser($buyer)->withHeader('Idempotency-Key', 'rfq-test-0002')
            ->postJson('/api/v2/procurement/requests', [...$payload, 'quantity' => 6])
            ->assertConflict()
            ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request payload.');

        $this->assertDatabaseCount('procurement_requests', 1);
    }

    public function test_expired_idempotency_records_can_be_reused_and_pruned(): void
    {
        [$buyer, $organization] = $this->company('buyer-idempotency-expired');
        $payload = [
            'organization_id' => $organization->id,
            'title' => 'Reusable RFQ key',
            'quantity' => 2,
            'unit' => 'unit',
        ];

        $this->asUser($buyer)->withHeader('Idempotency-Key', 'rfq-test-0003')
            ->postJson('/api/v2/procurement/requests', $payload)->assertCreated();
        IdempotencyRecord::query()->update(['expires_at' => now()->subMinute()]);

        $this->asUser($buyer)->withHeader('Idempotency-Key', 'rfq-test-0003')
            ->postJson('/api/v2/procurement/requests', [...$payload, 'quantity' => 3])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');

        IdempotencyRecord::query()->update(['expires_at' => now()->subMinute()]);
        $this->artisan('idempotency:prune')->assertSuccessful();
        $this->assertDatabaseCount('idempotency_records', 0);
        $this->assertDatabaseCount('procurement_requests', 2);
    }

    public function test_selected_supplier_rfq_supports_items_attachments_quote_versions_comparison_and_audit(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-advanced-rfq');
        [$supplierA, $supplierAOrganization] = $this->company('supplier-a-advanced-rfq');
        [$supplierB, $supplierBOrganization] = $this->company('supplier-b-advanced-rfq');
        [$outsider] = $this->company('supplier-outsider-advanced-rfq');

        $requestId = $this->asUser($buyer)->withHeader('Idempotency-Key', 'advanced-rfq-0001')
            ->postJson('/api/v2/procurement/requests', [
                'organization_id' => $buyerOrganization->id,
                'target_supplier_organization_ids' => [$supplierAOrganization->id, $supplierBOrganization->id],
                'title' => 'Multi-item packaging tender',
                'quantity' => 100,
                'unit' => 'batch',
                'status' => 'published',
                'response_deadline' => now()->addWeek()->toIso8601String(),
                'items' => [
                    ['name' => 'Carton', 'quantity' => 500, 'unit' => 'box', 'specifications' => ['gsm' => 350]],
                    ['name' => 'Insert', 'quantity' => 500, 'unit' => 'piece'],
                ],
                'attachments' => [['url' => 'https://buyer.example.com/specification.pdf', 'name' => 'Specification', 'type' => 'document']],
            ])->assertCreated()->assertJsonCount(2, 'data.items')->json('data.id');

        $this->asUser($outsider)->getJson("/api/v2/procurement/requests/{$requestId}")->assertNotFound();
        $this->asUser($supplierA)->getJson("/api/v2/procurement/requests/{$requestId}")
            ->assertOk()->assertJsonCount(2, 'data.selected_supplier_organizations');

        $quoteA = $this->asUser($supplierA)->withHeader('Idempotency-Key', 'advanced-quote-a-0001')
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
                'supplier_organization_id' => $supplierAOrganization->id,
                'amount' => 9200,
                'lead_time_days' => 14,
                'valid_until' => now()->addDays(5)->toDateString(),
                'delivery_terms' => 'FOB Cairo, 50% advance.',
                'attachments' => [['url' => 'https://supplier-a.example.com/quote.pdf', 'name' => 'Quote']],
            ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $this->asUser($supplierA)->withHeader('Idempotency-Key', 'advanced-quote-a-revise-0001')
            ->patchJson("/api/v2/procurement/requests/{$requestId}/quotes/{$quoteA}", [
                'amount' => 8800,
                'lead_time_days' => 12,
                'delivery_terms' => 'FOB Cairo, net 30 after approval.',
            ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.amount', '8800.00');

        $this->asUser($supplierB)->withHeader('Idempotency-Key', 'advanced-quote-b-0001')
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
                'supplier_organization_id' => $supplierBOrganization->id,
                'amount' => 9000,
                'lead_time_days' => 8,
            ])->assertCreated();

        $this->asUser($buyer)->getJson("/api/v2/procurement/requests/{$requestId}/quotes/compare")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $quoteA)
            ->assertJsonPath('data.0.amount_above_lowest', 0);
        $this->asUser($buyer)->getJson("/api/v2/procurement/requests/{$requestId}/audit")
            ->assertOk()->assertJsonFragment(['event' => 'rfq_published'])
            ->assertJsonFragment(['event' => 'quote_revised']);
        $this->assertDatabaseHas('procurement_quote_revisions', ['procurement_quote_id' => $quoteA, 'version' => 1]);
    }
}
