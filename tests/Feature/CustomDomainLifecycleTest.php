<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Stores\DnsTxtLookup;
use App\Services\Stores\StoreDomainResolver;
use App\Services\Stores\StoreDomainService;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Custom-domain lifecycle: attach -> verify -> promote -> detach, plus the
 * ownership invariants that keep one domain bound to exactly one store.
 */
class CustomDomainLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private StoreDomainService $domains;

    private StoreService $stores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domains = app(StoreDomainService::class);
        $this->stores = new StoreService;
    }

    private function makeStore(string $slug): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->stores->syncSubdomain($store);

        return $store;
    }

    /** Make DNS return whatever the challenge expects, so verification succeeds. */
    private function fakeDnsFor(StoreDomain $domain): void
    {
        $value = $domain->verificationTxtValue();
        $this->swap(DnsTxtLookup::class, new class($value) extends DnsTxtLookup
        {
            public function __construct(private readonly ?string $value) {}

            public function txt(string $name): array
            {
                return $this->value === null ? [] : [$this->value];
            }
        });
        $this->domains = app()->makeWith(StoreDomainService::class, []);
    }

    private function fakeDnsEmpty(): void
    {
        $this->swap(DnsTxtLookup::class, new class extends DnsTxtLookup
        {
            public function txt(string $name): array
            {
                return [];
            }
        });
        $this->domains = app()->makeWith(StoreDomainService::class, []);
    }

    // ------------------------------------------------------------- normalising

    public function test_hosts_are_normalised_lowercased_and_trimmed(): void
    {
        $this->assertSame('pharma-eg.com', $this->domains->normalize('  PHARMA-EG.com. '));
        $this->assertSame('shop.company.org', $this->domains->normalize('https://Shop.Company.ORG/path'));
        $this->assertSame('myshop.net', $this->domains->normalize('myshop.net:8443'));
    }

    public function test_invalid_hostnames_are_rejected(): void
    {
        foreach (['', 'not a domain', 'no-tld', '-bad.com', 'bad-.com', '192.168.0.1', 'exa mple.com'] as $bad) {
            try {
                $this->domains->assertValidHost($this->domains->normalize($bad));
                $this->fail("Expected [$bad] to be rejected.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_platform_owned_hosts_cannot_be_claimed(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();

        $this->expectException(ValidationException::class);
        $this->domains->assertValidHost($base);
    }

    public function test_subdomains_of_the_platform_base_domain_cannot_be_claimed(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();

        $this->expectException(ValidationException::class);
        $this->domains->assertValidHost('anything.'.$base);
    }

    // ------------------------------------------------------------------ attach

    public function test_attaching_a_domain_starts_pending_and_does_not_resolve(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->assertSame(StoreDomain::STATUS_PENDING, $domain->status);
        $this->assertFalse($domain->is_primary);
        $this->assertNotNull($domain->verification_token);

        // Unverified: not servable, so the host resolves to nothing.
        $this->assertNull(app(StoreDomainResolver::class)->resolve('pharma-eg.com'));
    }

    public function test_duplicate_domain_on_the_same_store_is_rejected(): void
    {
        $store = $this->makeStore('nike');
        $this->domains->attach($store, 'myshop.net');

        $this->expectException(ValidationException::class);
        $this->domains->attach($store, 'myshop.net');
    }

    public function test_domain_owned_by_another_store_is_never_transferred(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');

        $domain = $this->domains->attach($a, 'rstwsf.com');

        try {
            $this->domains->attach($b, 'rstwsf.com');
            $this->fail('Expected cross-store attach to be rejected.');
        } catch (ValidationException) {
            // Ownership must be completely untouched.
            $this->assertSame((int) $a->id, (int) $domain->fresh()->store_id);
            $this->assertSame(1, StoreDomain::query()->where('host', 'rstwsf.com')->count());
        }
    }

    public function test_attach_is_case_insensitive_for_duplicate_detection(): void
    {
        $store = $this->makeStore('nike');
        $this->domains->attach($store, 'MyShop.NET');

        $this->expectException(ValidationException::class);
        $this->domains->attach($store, 'myshop.net');
    }

    // ------------------------------------------------------------ verification

    public function test_verification_succeeds_when_the_txt_record_matches(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($domain);

        $this->assertTrue($this->domains->verify($domain));

        $domain->refresh();
        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertNull($domain->last_error);

        // Now servable.
        $this->assertSame((int) $store->id, (int) app(StoreDomainResolver::class)->resolve('pharma-eg.com')->id);
    }

    public function test_verification_fails_and_records_an_error_when_the_record_is_absent(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsEmpty();

        $this->assertFalse($this->domains->verify($domain));

        $domain->refresh();
        $this->assertSame(StoreDomain::STATUS_REJECTED, $domain->status);
        $this->assertNotNull($domain->last_error);
        $this->assertNull(app(StoreDomainResolver::class)->resolve('pharma-eg.com'));
    }

    public function test_a_transient_dns_failure_does_not_take_a_live_domain_offline(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($domain);
        $this->domains->verify($domain);

        // A later check fails (resolver blip) — the domain must keep serving.
        $this->fakeDnsEmpty();
        $this->domains->verify($domain->refresh());

        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->last_error);
    }

    public function test_verification_record_name_and_value_are_stable(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'myshop.net');

        $this->assertSame('_sellchase-challenge.myshop.net', $this->domains->verificationRecordName($domain));
        $this->assertStringStartsWith('sellchase-domain-verification=', (string) $domain->verificationTxtValue());
    }

    // ---------------------------------------------------------------- primary

    public function test_unverified_domain_cannot_be_made_primary(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->expectException(ValidationException::class);
        $this->domains->makePrimary($domain);
    }

    public function test_promoting_a_domain_demotes_every_other_and_leaves_exactly_one_primary(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($domain);
        $this->domains->verify($domain);
        $this->domains->makePrimary($domain->refresh());

        $primaries = $store->domains()->where('is_primary', true)->get();
        $this->assertCount(1, $primaries);
        $this->assertSame('pharma-eg.com', $primaries->first()->host);
    }

    public function test_secondary_domains_remain_servable_and_redirect_to_primary(): void
    {
        $store = $this->makeStore('nike');

        $primary = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($primary);
        $this->domains->verify($primary);
        $this->domains->makePrimary($primary->refresh());

        $secondary = $this->domains->attach($store, 'myshop.net');
        $this->fakeDnsFor($secondary);
        $this->domains->verify($secondary);

        $ctx = app(StoreDomainResolver::class)->resolveContext('myshop.net');

        $this->assertNotNull($ctx);
        $this->assertTrue($ctx->isAlias);
        $this->assertSame('pharma-eg.com', $ctx->canonicalHost);
    }

    public function test_disabling_a_domain_stops_it_serving_and_restores_a_primary(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($domain);
        $this->domains->verify($domain);
        $this->domains->makePrimary($domain->refresh());

        $this->domains->disable($domain->refresh());

        $this->assertNull(app(StoreDomainResolver::class)->resolve('pharma-eg.com'));
        // The platform subdomain takes over as canonical again.
        $this->assertSame(
            'nike.'.app(StoreDomainResolver::class)->baseDomain(),
            $store->refresh()->domains()->where('is_primary', true)->value('host'),
        );
    }

    public function test_detaching_a_custom_domain_frees_it_for_another_store(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');

        $domain = $this->domains->attach($a, 'rstwsf.com');
        $this->domains->detach($domain);

        // Only now may another store claim it.
        $reattached = $this->domains->attach($b, 'rstwsf.com');
        $this->assertSame((int) $b->id, (int) $reattached->store_id);
    }

    public function test_platform_subdomain_cannot_be_detached(): void
    {
        $store = $this->makeStore('nike');
        $subdomain = $store->domains()->where('type', 'subdomain')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->domains->detach($subdomain);
    }

    // ------------------------------------------------------- BUG #2: slug edit

    public function test_slug_change_never_demotes_a_live_custom_domain(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDnsFor($domain);
        $this->domains->verify($domain);
        $this->domains->makePrimary($domain->refresh());

        // Renaming the store must NOT hand the canonical host back to the subdomain.
        $this->stores->update($store, ['slug' => 'nike-renamed']);

        $this->assertTrue($domain->fresh()->is_primary, 'Custom domain lost primary on slug change.');
        $this->assertSame(
            'pharma-eg.com',
            app(StoreDomainResolver::class)->resolveContext('pharma-eg.com')->canonicalHost,
        );

        // The new subdomain exists as a secondary alias that redirects to the custom domain.
        $newSub = 'nike-renamed.'.app(StoreDomainResolver::class)->baseDomain();
        $ctx = app(StoreDomainResolver::class)->resolveContext($newSub);
        $this->assertNotNull($ctx);
        $this->assertTrue($ctx->isAlias);
        $this->assertSame('pharma-eg.com', $ctx->canonicalHost);
    }

    public function test_slug_change_never_moves_a_host_to_another_store(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();

        $a = $this->makeStore('alpha');
        // 'alpha' renames away, leaving alpha.<base> as its alias.
        $this->stores->update($a, ['slug' => 'alpha-two']);
        $this->assertSame((int) $a->id, (int) StoreDomain::query()->where('host', 'alpha.'.$base)->value('store_id'));

        // Another store now tries to take the freed slug.
        $b = $this->makeStore('beta');
        $this->stores->update($b, ['slug' => 'alpha']);

        // Store A must still own its alias — no silent ownership transfer.
        $this->assertSame(
            (int) $a->id,
            (int) StoreDomain::query()->where('host', 'alpha.'.$base)->value('store_id'),
            'Alias row was hijacked by another store.',
        );
        // And B got a distinct, non-colliding host of its own.
        $this->assertNotSame('alpha.'.$base, $b->refresh()->domains()->where('is_primary', true)->value('host'));
    }

    public function test_every_host_belongs_to_exactly_one_store(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');
        $this->stores->update($a, ['slug' => 'gamma']);
        $this->stores->update($b, ['slug' => 'delta']);

        $duplicated = StoreDomain::query()
            ->selectRaw('host, COUNT(DISTINCT store_id) AS owners')
            ->groupBy('host')
            ->havingRaw('COUNT(DISTINCT store_id) > 1')
            ->get();

        $this->assertCount(0, $duplicated, 'A host is owned by more than one store.');
    }

    // -------------------------------------------------------------------- ssl

    public function test_ssl_status_is_tracked_without_hardcoding_a_provider(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->domains->recordSslStatus(
            $domain,
            StoreDomain::SSL_ACTIVE,
            'lets-encrypt',
            now()->subDay(),
            now()->addMonths(3),
        );

        $domain->refresh();
        $this->assertSame(StoreDomain::SSL_ACTIVE, $domain->ssl_status);
        $this->assertSame('lets-encrypt', $domain->ssl_provider);
        $this->assertNotNull($domain->ssl_expires_at);
    }

    public function test_ssl_issuance_is_only_allowed_for_hosts_we_actually_serve(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        // Pending: a TLS provider must not issue for it yet.
        $this->assertFalse($this->domains->isIssuableHost('pharma-eg.com'));
        $this->assertFalse($this->domains->isIssuableHost('never-registered.example.com'));

        $this->fakeDnsFor($domain);
        $this->domains->verify($domain);

        $this->assertTrue($this->domains->isIssuableHost('pharma-eg.com'));
    }
}
