<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Stores\StoreDomainResolver;
use App\Services\Stores\StoreDomainService;
use App\Services\Stores\TrustedHostRegistry;
use App\Services\StoreService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end custom-domain behaviour over HTTP: resolution, redirects, host
 * trust, spoofing and tenant isolation — for BOTH supplier and merchant stores.
 */
class CustomDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    private StoreService $stores;

    private StoreDomainService $domains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->stores = new StoreService;
        $this->domains = app(StoreDomainService::class);
    }

    /** A store with catalog content, a connected+verified custom domain, promoted to primary. */
    private function makeStoreWithDomain(string $slug, string $host, string $ownerType = 'merchant'): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole($ownerType === 'supplier' ? 'Supplier' : 'Merchant');

        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => $ownerType,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->stores->syncSubdomain($store);

        $cat = Category::create(['store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        Product::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => ucfirst($slug).' One', 'slug' => 'one', 'price' => 10, 'is_active' => true,
        ]);

        $domain = StoreDomain::create([
            'store_id' => $store->id,
            'host' => $host,
            'type' => 'custom',
            'status' => StoreDomain::STATUS_VERIFIED,
            'is_primary' => false,
        ]);
        $this->domains->makePrimary($domain);

        return [$user, $store, $domain];
    }

    // ------------------------------------------------------------- resolution

    public function test_merchant_custom_domain_serves_the_storefront(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        $this->get('http://pharma-eg.com/')->assertOk()->assertSee('Nike');
    }

    public function test_supplier_custom_domain_serves_the_storefront(): void
    {
        $this->makeStoreWithDomain('acme', 'myshop.net', 'supplier');

        $this->get('http://myshop.net/')->assertOk()->assertSee('Acme');
    }

    public function test_arbitrary_tlds_work_without_code_changes(): void
    {
        $this->makeStoreWithDomain('gamma', 'store.company.org');

        $this->get('http://store.company.org/')->assertOk()->assertSee('Gamma');
    }

    public function test_unverified_custom_domain_does_not_serve(): void
    {
        [, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $this->domains->attach($store, 'rstwsf.com'); // pending

        $this->get('http://rstwsf.com/')->assertNotFound();
    }

    public function test_unknown_domain_is_rejected(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        $this->get('http://never-connected.example.com/')->assertNotFound();
        $this->get('http://never-connected.example.com/products')->assertNotFound();
    }

    // -------------------------------------------------------------- redirects

    public function test_subdomain_redirects_to_the_primary_custom_domain(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $base = app(StoreDomainResolver::class)->baseDomain();

        $this->get("http://nike.{$base}/products?page=2")
            ->assertStatus(301)
            ->assertRedirect('http://pharma-eg.com/products?page=2');
    }

    public function test_secondary_custom_domain_redirects_to_primary(): void
    {
        [, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        StoreDomain::create([
            'store_id' => $store->id, 'host' => 'myshop.net',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED, 'is_primary' => false,
        ]);

        $this->get('http://myshop.net/products')
            ->assertStatus(301)
            ->assertRedirect('http://pharma-eg.com/products');
    }

    public function test_http_is_upgraded_to_https_when_forced(): void
    {
        config()->set('sellchase.storefront.force_https', true);
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        $this->get('http://pharma-eg.com/products')
            ->assertStatus(301)
            ->assertRedirect('https://pharma-eg.com/products');
    }

    public function test_host_and_scheme_are_corrected_in_a_single_redirect(): void
    {
        config()->set('sellchase.storefront.force_https', true);
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $base = app(StoreDomainResolver::class)->baseDomain();

        // One hop, not two: alias host AND scheme fixed together.
        $this->get("http://nike.{$base}/products")
            ->assertStatus(301)
            ->assertRedirect('https://pharma-eg.com/products');
    }

    public function test_https_is_not_forced_for_an_unknown_host(): void
    {
        config()->set('sellchase.storefront.force_https', true);
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        // An unknown host must 404 outright, never receive a followable redirect.
        $this->get('http://never-connected.example.com/')->assertNotFound();
    }

    // ------------------------------------------------------------ host trust

    public function test_trusted_host_registry_trusts_verified_custom_domains(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        $this->assertTrue(
            app(TrustedHostRegistry::class)->isTrusted('pharma-eg.com'),
            'A verified custom domain must be a trusted host.',
        );
    }

    public function test_trusted_host_registry_does_not_trust_unknown_or_unverified_domains(): void
    {
        [, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $this->domains->attach($store, 'rstwsf.com'); // pending, not verified

        $registry = app(TrustedHostRegistry::class);

        foreach (['attacker.example', 'rstwsf.com', 'pharma-eg.com.evil.io'] as $host) {
            $this->assertFalse($registry->isTrusted($host), "[$host] must not be trusted.");
        }
    }

    public function test_trusted_host_registry_still_trusts_platform_hosts(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();
        $registry = app(TrustedHostRegistry::class);

        $this->assertTrue($registry->isTrusted('nike.'.$base), 'Platform subdomains must remain trusted.');
        $this->assertTrue($registry->isTrusted($base));
        $this->assertTrue($registry->isTrusted('localhost'));
    }

    public function test_trusted_host_lookup_is_cache_first_and_constant_time(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $registry = app(TrustedHostRegistry::class);

        // Model events warm the positive entry on verification, so the very
        // first lookup is already a cache hit — no query needed.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($registry->isTrusted('pharma-eg.com'));
        }

        $this->assertSame(0, $queries, 'Trusted-host lookups must not hit the database on a cache hit.');
    }

    public function test_unknown_host_lookups_are_negative_cached(): void
    {
        $registry = app(TrustedHostRegistry::class);

        $this->assertFalse($registry->isTrusted('enumeration-probe.example'));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        // A flood of repeats for the same unknown host must be absorbed by the
        // negative cache rather than becoming a query storm.
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($registry->isTrusted('enumeration-probe.example'));
        }

        $this->assertSame(0, $queries, 'Repeated unknown-host lookups must be served from the negative cache.');
    }

    // --------------------------------------------------------------- security

    public function test_host_header_spoofing_does_not_select_a_store(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        foreach (['pharma-eg.com.attacker.example', 'evil.com', 'pharma-eg.com.evil.io'] as $spoof) {
            $this->assertNull(
                app(StoreDomainResolver::class)->resolve($spoof),
                "[$spoof] must not resolve to a store.",
            );
        }
    }

    public function test_custom_domains_are_tenant_isolated(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $this->makeStoreWithDomain('adidas', 'myshop.net');

        // Each domain sees only its own catalog.
        $this->get('http://pharma-eg.com/')->assertOk()->assertSee('Nike')->assertDontSee('Adidas One');
        $this->get('http://myshop.net/')->assertOk()->assertSee('Adidas')->assertDontSee('Nike One');
    }

    public function test_sitemap_and_robots_use_the_custom_domain(): void
    {
        $this->makeStoreWithDomain('nike', 'pharma-eg.com');

        $this->get('http://pharma-eg.com/sitemap.xml')
            ->assertOk()
            ->assertSee('https://pharma-eg.com/', false);

        $this->get('http://pharma-eg.com/robots.txt')
            ->assertOk()
            ->assertSee('https://pharma-eg.com/sitemap.xml', false);
    }

    // ------------------------------------------------------------- api surface

    public function test_owner_can_connect_and_list_domains(): void
    {
        [$user, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $token = JwtTokenService::fromConfig()->issueAccessToken($user);

        $this->withToken($token)
            ->postJson("/api/v1/stores/{$store->id}/domains", ['host' => 'RSTWSF.com'])
            ->assertCreated()
            ->assertJsonPath('data.host', 'rstwsf.com')
            ->assertJsonPath('data.status', StoreDomain::STATUS_PENDING)
            ->assertJsonPath('data.verification.record_type', 'TXT');

        $this->withToken($token)
            ->getJson("/api/v1/stores/{$store->id}/domains")
            ->assertOk()
            ->assertJsonCount(3, 'data'); // subdomain + primary custom + new pending
    }

    public function test_owner_cannot_connect_a_domain_owned_by_another_store(): void
    {
        [$user, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $this->makeStoreWithDomain('adidas', 'myshop.net');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v1/stores/{$store->id}/domains", ['host' => 'myshop.net'])
            ->assertStatus(422);
    }

    public function test_owner_cannot_manage_another_stores_domains(): void
    {
        [$user] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        [, $other] = $this->makeStoreWithDomain('adidas', 'myshop.net');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->getJson("/api/v1/stores/{$other->id}/domains")
            ->assertForbidden();
    }

    public function test_platform_domains_are_rejected_by_the_api(): void
    {
        [$user, $store] = $this->makeStoreWithDomain('nike', 'pharma-eg.com');
        $base = app(StoreDomainResolver::class)->baseDomain();

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v1/stores/{$store->id}/domains", ['host' => 'takeover.'.$base])
            ->assertStatus(422);
    }
}
