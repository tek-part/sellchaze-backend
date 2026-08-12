<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'is_active' => true, 'pending_approval' => false]);
    }

    private function asUser(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    /** @return array{0: User, 1: Organization} */
    private function company(): array
    {
        $owner = $this->user('owner'.uniqid().'@example.com');
        $organization = Organization::create(['name' => 'Quota Co', 'slug' => 'quota-'.uniqid()]);
        $organization->memberships()->create([
            'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        return [$owner, $organization];
    }

    public function test_plan_catalog_comes_from_separate_prices_and_entitlements(): void
    {
        [$owner] = $this->company();

        $this->asUser($owner)->getJson('/api/v2/plans')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.slug', 'trial')
            ->assertJsonPath('data.0.entitlements.stores', 1)
            ->assertJsonPath('data.2.slug', 'growth')
            ->assertJsonPath('data.2.entitlements.advanced_themes', true)
            ->assertJsonPath('data.3.prices.0.quote_required', true);
    }

    public function test_trial_is_default_and_store_quota_is_enforced(): void
    {
        [$owner, $organization] = $this->company();

        $this->asUser($owner)->getJson("/api/v2/organizations/{$organization->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.plan.slug', 'trial')
            ->assertJsonPath('data.quotas.stores', 1)
            ->assertJsonPath('data.usage.stores', 0);

        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/stores", [
            'name' => 'First', 'slug' => 'trial-first-'.uniqid(),
        ])->assertCreated();

        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/stores", [
            'name' => 'Second', 'slug' => 'trial-second-'.uniqid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'quota_exceeded');
    }

    public function test_growth_subscription_unlocks_multi_store_and_emits_event(): void
    {
        [$owner, $organization] = $this->company();

        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/subscription", [
            'plan' => 'growth', 'billing_cycle' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.plan.slug', 'growth')
            ->assertJsonPath('data.quotas.stores', 5)
            ->assertJsonPath('data.features.advanced_themes', true);

        foreach (range(1, 5) as $index) {
            $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/stores", [
                'name' => "Store {$index}", 'slug' => 'growth-'.$index.'-'.uniqid(),
            ])->assertCreated();
        }
        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/stores", [
            'name' => 'Store 6', 'slug' => 'growth-six-'.uniqid(),
        ])->assertUnprocessable();

        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_id' => (string) $organization->id,
            'event_type' => 'SubscriptionActivated',
        ]);
    }

    public function test_scale_requires_sales_quote_and_outsider_cannot_change_subscription(): void
    {
        [$owner, $organization] = $this->company();
        $outsider = $this->user('outsider@example.com');

        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/subscription", [
            'plan' => 'scale', 'billing_cycle' => 'monthly',
        ])->assertUnprocessable();
        $this->asUser($outsider)->postJson("/api/v2/organizations/{$organization->id}/subscription", [
            'plan' => 'growth', 'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    public function test_trial_seat_quota_counts_owner_and_blocks_fourth_member(): void
    {
        [$owner, $organization] = $this->company();
        $members = [
            $this->user('one@example.com'),
            $this->user('two@example.com'),
            $this->user('three@example.com'),
        ];

        foreach (array_slice($members, 0, 2) as $member) {
            $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/memberships", [
                'email' => $member->email, 'role' => 'member',
            ])->assertCreated();
        }
        $this->asUser($owner)->postJson("/api/v2/organizations/{$organization->id}/memberships", [
            'email' => $members[2]->email, 'role' => 'member',
        ])->assertUnprocessable()->assertJsonPath('error.key', 'seats');
    }
}
