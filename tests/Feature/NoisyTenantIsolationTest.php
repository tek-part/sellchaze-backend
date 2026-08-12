<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoisyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausting_one_organization_read_budget_does_not_throttle_another(): void
    {
        config([
            'performance.api_ip_per_minute' => 100,
            'performance.tenant_read_per_minute' => 2,
        ]);

        [$noisy, $noisyOrganization] = $this->member('noisy@example.com', 'noisy-company');
        [$quiet, $quietOrganization] = $this->member('quiet@example.com', 'quiet-company');

        $noisyClient = $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($noisy));
        $noisyClient->getJson('/api/v1/feed')->assertOk();
        $noisyClient->getJson('/api/v1/notifications')->assertOk();
        $noisyClient->getJson('/api/v1/feed')->assertTooManyRequests();

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($quiet))
            ->getJson('/api/v1/feed')
            ->assertOk();

        $this->assertNotSame($noisyOrganization->id, $quietOrganization->id);
    }

    public function test_exhausting_one_storefront_budget_does_not_throttle_another_store_on_same_ip(): void
    {
        config([
            'performance.api_ip_per_minute' => 100,
            'performance.storefront_read_per_minute' => 2,
            'performance.storefront_ip_per_minute' => 100,
        ]);

        $this->store('Noisy Store', 'noisy-store');
        $this->store('Quiet Store', 'quiet-store');

        $this->getJson('http://noisy-store.sellchase.com/api/v1/storefront/products')->assertOk();
        $this->getJson('http://noisy-store.sellchase.com/api/v1/storefront/categories')->assertOk();
        $this->getJson('http://noisy-store.sellchase.com/api/v1/storefront/products')->assertTooManyRequests();

        $this->getJson('http://quiet-store.sellchase.com/api/v1/storefront/products')->assertOk();
    }

    /** @return array{User, Organization} */
    private function member(string $email, string $slug): array
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'pending_approval' => false]);
        $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function store(string $name, string $slug): Store
    {
        $owner = User::factory()->create();
        $store = Store::query()->create([
            'owner_user_id' => $owner->id,
            'owner_type' => 'merchant',
            'name' => $name,
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        StoreDomain::query()->create([
            'store_id' => $store->id,
            'host' => $slug.'.sellchase.com',
            'type' => 'subdomain',
            'is_primary' => true,
        ]);

        return $store;
    }
}
