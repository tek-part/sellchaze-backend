<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationConnection;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug, string $role = 'owner', array $permissions = []): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $organization = Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
            'permissions' => $permissions,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function authenticated(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    public function test_two_companies_can_form_one_audited_connection(): void
    {
        [$buyer, $buyerOrganization] = $this->company('buyer-network');
        [$supplier, $supplierOrganization] = $this->company('supplier-network');

        $connectionId = $this->authenticated($buyer)
            ->withHeader('Idempotency-Key', 'connection-request-1')
            ->postJson("/api/v2/organizations/{$buyerOrganization->id}/connections", [
                'target_organization_id' => $supplierOrganization->id,
                'message' => 'We would like to source from your factory.',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'outgoing')
            ->json('data.id');

        $this->authenticated($supplier)
            ->withHeader('Idempotency-Key', 'connection-accept-1')
            ->postJson("/api/v2/organizations/{$supplierOrganization->id}/connections/{$connectionId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.direction', 'incoming');

        $this->authenticated($buyer)
            ->getJson("/api/v2/organizations/{$buyerOrganization->id}/connections?status=accepted")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization.id', $supplierOrganization->id);

        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'ConnectionRequested',
            'aggregate_id' => (string) $connectionId,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'event_type' => 'ConnectionAccepted',
            'aggregate_id' => (string) $connectionId,
        ]);
    }

    public function test_connection_rules_prevent_self_links_duplicates_and_wrong_party_responses(): void
    {
        [$alphaUser, $alpha] = $this->company('alpha-network');
        [$betaUser, $beta] = $this->company('beta-network');
        [$outsider, $gamma] = $this->company('gamma-network');

        $this->authenticated($alphaUser)
            ->withHeader('Idempotency-Key', 'self-connection')
            ->postJson("/api/v2/organizations/{$alpha->id}/connections", ['target_organization_id' => $alpha->id])
            ->assertUnprocessable();

        $connectionId = $this->authenticated($alphaUser)
            ->withHeader('Idempotency-Key', 'alpha-beta-1')
            ->postJson("/api/v2/organizations/{$alpha->id}/connections", ['target_organization_id' => $beta->id])
            ->assertCreated()->json('data.id');

        $this->authenticated($betaUser)
            ->withHeader('Idempotency-Key', 'beta-alpha-duplicate')
            ->postJson("/api/v2/organizations/{$beta->id}/connections", ['target_organization_id' => $alpha->id])
            ->assertUnprocessable();

        $this->authenticated($outsider)
            ->withHeader('Idempotency-Key', 'outsider-accept')
            ->postJson("/api/v2/organizations/{$gamma->id}/connections/{$connectionId}/accept")
            ->assertNotFound();

        $this->authenticated($alphaUser)
            ->withHeader('Idempotency-Key', 'initiator-accept')
            ->postJson("/api/v2/organizations/{$alpha->id}/connections/{$connectionId}/accept")
            ->assertForbidden();
    }

    public function test_delegated_member_permission_can_manage_connections_but_plain_member_cannot(): void
    {
        [, $source] = $this->company('delegated-source');
        [, $target] = $this->company('delegated-target');
        $delegated = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $plain = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $source->memberships()->create([
            'user_id' => $delegated->id, 'role' => 'member', 'permissions' => ['manage_connections'],
            'status' => 'active', 'joined_at' => now(),
        ]);
        $source->memberships()->create([
            'user_id' => $plain->id, 'role' => 'member', 'permissions' => [],
            'status' => 'active', 'joined_at' => now(),
        ]);

        $this->authenticated($plain)
            ->withHeader('Idempotency-Key', 'plain-member-request')
            ->postJson("/api/v2/organizations/{$source->id}/connections", ['target_organization_id' => $target->id])
            ->assertForbidden();

        $this->authenticated($delegated)
            ->withHeader('Idempotency-Key', 'delegated-member-request')
            ->postJson("/api/v2/organizations/{$source->id}/connections", ['target_organization_id' => $target->id])
            ->assertCreated();

        $this->assertSame(1, OrganizationConnection::query()->count());
    }
}
