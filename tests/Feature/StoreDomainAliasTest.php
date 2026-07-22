<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Stores\StoreDomainResolver;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StoreDomainAliasTest extends TestCase
{
    use RefreshDatabase;

    private StoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StoreService;
    }

    private function makeStore(string $slug = 'nike'): Store
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->service->syncSubdomain($store);

        return $store;
    }

    public function test_slug_change_keeps_old_host_as_alias_and_promotes_new_primary(): void
    {
        $store = $this->makeStore('nike');
        $this->service->update($store, ['slug' => 'nike2']);

        $old = StoreDomain::where('host', 'nike.sellchase.com')->first();
        $new = StoreDomain::where('host', 'nike2.sellchase.com')->first();

        $this->assertNotNull($old, 'old host row should be retained as history');
        $this->assertFalse($old->is_primary, 'old host should be demoted to alias');
        $this->assertTrue($new->is_primary, 'new host should be primary');
        $this->assertSame($store->id, $old->store_id);
    }

    public function test_resolver_flags_alias_and_canonical_host(): void
    {
        $store = $this->makeStore('nike');
        $this->service->update($store, ['slug' => 'nike2']);
        $resolver = new StoreDomainResolver;

        $aliasCtx = $resolver->resolveContext('nike.sellchase.com');
        $this->assertNotNull($aliasCtx);
        $this->assertTrue($aliasCtx->isAlias);
        $this->assertSame('nike2.sellchase.com', $aliasCtx->canonicalHost);
        $this->assertSame($store->id, $aliasCtx->store->id);

        $primaryCtx = $resolver->resolveContext('nike2.sellchase.com');
        $this->assertFalse($primaryCtx->isAlias);
    }

    public function test_alias_host_issues_301_to_canonical_host(): void
    {
        $store = $this->makeStore('nike');
        $this->service->update($store, ['slug' => 'nike2']);

        $this->get('http://nike.sellchase.com/api/v1/storefront/resolve')
            ->assertStatus(301)
            ->assertHeader('Location', 'http://nike2.sellchase.com/api/v1/storefront/resolve');

        $this->getJson('http://nike2.sellchase.com/api/v1/storefront/resolve')
            ->assertOk()
            ->assertJsonPath('data.slug', 'nike2');
    }

    public function test_resolution_is_cached_and_invalidated_on_slug_change(): void
    {
        $store = $this->makeStore('nike');
        $resolver = new StoreDomainResolver;

        $resolver->resolveContext('nike.sellchase.com');
        $this->assertTrue(Cache::has(StoreDomainResolver::cacheKey('nike.sellchase.com')), 'resolution should be cached');

        // Changing the slug demotes nike -> alias (model save) which must clear its cache.
        $this->service->update($store, ['slug' => 'nikex']);
        $this->assertFalse(Cache::has(StoreDomainResolver::cacheKey('nike.sellchase.com')), 'cache should be invalidated on domain change');
    }
}
