<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the ResolveStoreFromHost middleware through the public
 * /storefront/resolve endpoint.
 */
class ResolveStoreFromHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => 'Nike',
            'slug' => 'nike',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        StoreDomain::create([
            'store_id' => $store->id,
            'host' => 'nike.sellchase.com',
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
    }

    /**
     * Absolute URLs are used so the request host is the subdomain under test.
     * (A relative URL would inherit APP_URL's host and ignore any Host header.)
     */
    public function test_valid_subdomain_resolves_store(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/resolve')
            ->assertOk()
            ->assertJsonPath('data.slug', 'nike')
            ->assertJsonPath('data.host', 'nike.sellchase.com');
    }

    public function test_reserved_subdomain_is_not_resolved(): void
    {
        $this->getJson('http://admin.sellchase.com/api/v1/storefront/resolve')
            ->assertNotFound();
    }

    public function test_unknown_subdomain_returns_404(): void
    {
        $this->getJson('http://ghost.sellchase.com/api/v1/storefront/resolve')
            ->assertNotFound();
    }

    public function test_foreign_host_returns_404(): void
    {
        $this->getJson('http://nike.evil.com/api/v1/storefront/resolve')
            ->assertNotFound();
    }

    public function test_base_domain_returns_404(): void
    {
        $this->getJson('http://sellchase.com/api/v1/storefront/resolve')
            ->assertNotFound();
    }
}
