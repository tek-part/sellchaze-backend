<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'pending_approval' => false,
        ]);
    }

    private function asUser(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    public function test_organization_creation_is_atomic_and_records_domain_event(): void
    {
        $owner = $this->user('owner@example.com');

        $body = $this->asUser($owner)->postJson('/api/v2/organizations', [
            'name' => 'Acme Distribution',
            'slug' => 'acme-distribution',
            'type' => 'distributor',
            'default_locale' => 'en',
            'default_currency' => 'USD',
            'timezone' => 'UTC',
        ])->assertCreated()
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.slug', 'acme-distribution')
            ->json('data');

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $body['id'],
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_id' => (string) $body['id'],
            'event_type' => 'OrganizationCreated',
        ]);
    }

    public function test_non_member_cannot_read_or_mutate_another_tenant(): void
    {
        $owner = $this->user('owner@example.com');
        $outsider = $this->user('outsider@example.com');
        $organization = Organization::create(['name' => 'Private Co', 'slug' => 'private-co']);
        $organization->memberships()->create([
            'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->asUser($outsider)->getJson("/api/v2/organizations/{$organization->id}")->assertForbidden();
        $this->asUser($outsider)->patchJson("/api/v2/organizations/{$organization->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->asUser($outsider)->postJson("/api/v2/organizations/{$organization->id}/stores", [
            'name' => 'Hijacked Store', 'slug' => 'hijacked-store',
        ])->assertForbidden();
        $this->assertSame('Private Co', $organization->fresh()->name);
    }

    public function test_owner_can_add_member_but_regular_member_cannot_manage_team(): void
    {
        $owner = $this->user('owner@example.com');
        $member = $this->user('member@example.com');
        $organization = Organization::create(['name' => 'Team Co', 'slug' => 'team-co']);
        $organization->memberships()->create([
            'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/memberships", [
            'email' => $member->email,
            'role' => 'member',
        ])->assertCreated()->assertJsonPath('data.role', 'member');

        $this->asUser($member)->getJson("/api/v2/organizations/{$organization->id}")->assertOk();
        $this->asUser($member)->postJson("/api/v2/organizations/{$organization->id}/memberships", [
            'email' => $owner->email,
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_company_can_own_multiple_stores_and_stores_are_tenant_scoped(): void
    {
        $owner = $this->user('owner@example.com');
        $outsider = $this->user('outsider@example.com');
        $organization = Organization::create([
            'name' => 'Multi Store Co', 'slug' => 'multi-store-co', 'default_currency' => 'EGP',
        ]);
        $organization->memberships()->create([
            'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/subscription", [
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
        ])->assertCreated();

        foreach ([['First', 'first-store'], ['Second', 'second-store']] as [$name, $slug]) {
            $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/stores", [
                'name' => $name,
                'slug' => $slug,
            ])->assertCreated()->assertJsonPath('data.organization_id', $organization->id);
        }

        $this->assertSame(2, Store::where('organization_id', $organization->id)->count());
        $this->assertSame(1, Store::where('organization_id', $organization->id)->where('is_primary', true)->count());
        $this->asUser($owner)->getJson("/api/v2/organizations/{$organization->id}/stores")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($outsider)->getJson("/api/v2/organizations/{$organization->id}/stores")->assertForbidden();
        $this->assertSame(2, OutboxMessage::where('event_type', 'StoreCreated')->count());
    }
}
