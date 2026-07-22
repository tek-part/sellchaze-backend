<?php

namespace Tests\Feature;

use App\Jobs\Domains\CheckDomainDnsJob;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Models\User;
use App\Notifications\DomainDisabledNotification;
use App\Notifications\DomainVerifiedNotification;
use App\Services\Stores\DnsTxtLookup;
use App\Services\Stores\DomainSslService;
use App\Services\Stores\Ssl\ReverseProxySslProvider;
use App\Services\Stores\Ssl\SslProviderManager;
use App\Services\Stores\Ssl\TlsProbe;
use App\Services\Stores\StoreDomainResolver;
use App\Services\Stores\StoreDomainService;
use App\Services\Stores\TrustedHostRegistry;
use App\Services\StoreService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Production-readiness hardening: defects found while auditing the Sprint 2
 * implementation against its own report.
 */
class CustomDomainHardeningTest extends TestCase
{
    use RefreshDatabase;

    private StoreDomainService $domains;

    private StoreService $stores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->domains = app(StoreDomainService::class);
        $this->stores = new StoreService;
    }

    private function makeStore(string $slug = 'nike'): Store
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');

        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->stores->syncSubdomain($store);

        return $store;
    }

    private function verifiedDomain(Store $store, string $host = 'pharma-eg.com'): StoreDomain
    {
        return StoreDomain::create([
            'store_id' => $store->id, 'host' => $host, 'type' => 'custom',
            'status' => StoreDomain::STATUS_VERIFIED, 'is_primary' => false,
            'verification_token' => str_repeat('a', 48),
            'verification_token_expires_at' => now()->addYear(),
        ])->refresh();
    }

    /** Bind a probe that returns a fixed certificate for any host. */
    private function fakeProbe(?array $cert): void
    {
        $this->swap(TlsProbe::class, new class($cert) extends TlsProbe
        {
            public function __construct(private ?array $cert)
            {
                parent::__construct();
            }

            public function inspect(string $host, int $port = 443): ?array
            {
                return $this->cert;
            }
        });

        app(SslProviderManager::class)->extend(
            'probe-test',
            fn ($c) => new ReverseProxySslProvider($c->make(TlsProbe::class)),
        );
        config()->set('sellchase.storefront.ssl.provider', 'probe-test');
    }

    // =====================================================================
    // DEFECT 1 — certificate must actually cover the host
    // =====================================================================

    public function test_certificate_for_a_different_host_is_not_reported_as_active(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store, 'pharma-eg.com');

        // The edge serves a perfectly valid certificate — for somebody else.
        $this->fakeProbe([
            'issuer' => 'Some CA',
            'fingerprint' => 'aa11',
            'san' => ['unrelated.example.com'],
            'issued_at' => new \DateTimeImmutable('-1 day'),
            'expires_at' => new \DateTimeImmutable('+80 days'),
        ]);

        app(DomainSslService::class)->refreshStatus($domain);

        $this->assertSame(
            StoreDomain::SSL_FAILED,
            $domain->fresh()->ssl_status,
            'A certificate that does not cover the host must never read as active.',
        );
    }

    public function test_expired_certificate_being_served_is_not_reported_as_active(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);

        $this->fakeProbe([
            'issuer' => 'Some CA',
            'fingerprint' => 'bb22',
            'san' => ['pharma-eg.com'],
            'issued_at' => new \DateTimeImmutable('-100 days'),
            'expires_at' => new \DateTimeImmutable('-1 day'),
        ]);

        app(DomainSslService::class)->refreshStatus($domain);

        $this->assertSame(StoreDomain::SSL_FAILED, $domain->fresh()->ssl_status);
    }

    public function test_matching_certificate_is_reported_as_active(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);

        $this->fakeProbe([
            'issuer' => 'Some CA',
            'fingerprint' => 'cc33',
            'san' => ['pharma-eg.com', 'www.pharma-eg.com'],
            'issued_at' => new \DateTimeImmutable('-1 day'),
            'expires_at' => new \DateTimeImmutable('+80 days'),
        ]);

        app(DomainSslService::class)->refreshStatus($domain);

        $this->assertSame(StoreDomain::SSL_ACTIVE, $domain->fresh()->ssl_status);
    }

    public function test_wildcard_san_matching_follows_rfc_6125(): void
    {
        $probe = new TlsProbe;

        // A wildcard covers exactly one label.
        $this->assertTrue($probe->certificateCovers(['*.example.com'], 'shop.example.com'));
        $this->assertTrue($probe->certificateCovers(['*.example.com'], 'SHOP.EXAMPLE.COM'));

        // ...not the apex, and not a deeper label.
        $this->assertFalse($probe->certificateCovers(['*.example.com'], 'example.com'));
        $this->assertFalse($probe->certificateCovers(['*.example.com'], 'a.b.example.com'));

        // ...and never a different domain that merely ends similarly.
        $this->assertFalse($probe->certificateCovers(['*.example.com'], 'shop.notexample.com'));
        $this->assertFalse($probe->certificateCovers([], 'example.com'));
    }

    // =====================================================================
    // DEFECT 2 — dead-letter handling
    // =====================================================================

    public function test_a_permanently_failed_job_records_state_and_audit(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        (new CheckDomainDnsJob($domain->id))->failed(new \RuntimeException('resolver unreachable'));

        $domain->refresh();

        $this->assertNotNull($domain->last_error);
        $this->assertNotNull($domain->last_checked_at);
        $this->assertDatabaseHas('store_domain_events', [
            'store_domain_id' => $domain->id,
            'event' => StoreDomainEvent::VERIFICATION_FAILED,
        ]);
    }

    public function test_dead_letter_handling_is_safe_for_a_deleted_domain(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $id = $domain->id;
        $domain->delete();

        // Must not throw — the domain can vanish before the dead-letter runs.
        (new CheckDomainDnsJob($id))->failed(new \RuntimeException('gone'));

        $this->assertTrue(true);
    }

    // =====================================================================
    // Host normalisation / IDN / Unicode edge cases
    // =====================================================================

    public function test_host_normalisation_rejects_structurally_invalid_hosts(): void
    {
        $resolver = app(StoreDomainResolver::class);

        foreach ([
            'victim.com:8080@evil.io',   // userinfo smuggling
            'victim.com/../evil',        // path traversal
            'victim.com#evil.io',        // fragment
            'victim.com?a=b',            // query
            'victim com',                // whitespace
            'victim..com',               // empty label
            '-victim.com',               // leading hyphen
            'victim-.com',               // trailing hyphen
            'victim.com:notaport',       // non-numeric port
            "victim.com\r\nX-Injected: 1", // header injection attempt
            "victim.com\tevil.io",       // embedded whitespace
        ] as $bad) {
            $this->assertNull(
                $resolver->normalizeHost($bad),
                "[{$bad}] must be rejected, not coerced into a valid host.",
            );
        }

        // Surrounding whitespace is trimmed, not treated as an attack — a
        // trailing newline is a common artefact of proxy header handling and
        // the trimmed result is still a structurally valid host.
        $this->assertSame('victim.com', $resolver->normalizeHost("  victim.com\n"));
    }

    public function test_host_normalisation_accepts_valid_hosts_including_punycode(): void
    {
        $resolver = app(StoreDomainResolver::class);

        $this->assertSame('pharma-eg.com', $resolver->normalizeHost('PHARMA-EG.com.'));
        $this->assertSame('pharma-eg.com', $resolver->normalizeHost('pharma-eg.com:8443'));
        $this->assertSame('xn--80ak6aa92e.com', $resolver->normalizeHost('XN--80AK6AA92E.com'));
        $this->assertSame('a.b.c.example.com', $resolver->normalizeHost('a.b.c.example.com'));
    }

    public function test_unicode_domains_are_stored_as_punycode(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension is not available.');
        }

        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'münchen.example');

        // The Host header carries punycode, so that is what must be persisted —
        // otherwise the stored row could never match an incoming request.
        $this->assertStringStartsWith('xn--', $domain->host);
        $this->assertSame($domain->host, app(StoreDomainResolver::class)->normalizeHost($domain->host));
    }

    public function test_a_unicode_and_punycode_form_of_one_domain_cannot_be_registered_twice(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension is not available.');
        }

        $store = $this->makeStore();
        $first = $this->domains->attach($store, 'münchen.example');

        // Submitting the already-encoded form must collide, not create a twin.
        $this->expectException(ValidationException::class);
        $this->domains->attach($store, $first->host);
    }

    // =====================================================================
    // Trusted-host registry: verify the ACTUAL complexity claim
    // =====================================================================

    public function test_cache_miss_costs_exactly_one_indexed_query(): void
    {
        $store = $this->makeStore();
        $this->verifiedDomain($store, 'pharma-eg.com');

        // Clear the entry the model event warmed, to force a genuine miss.
        app(TrustedHostRegistry::class)->forget('pharma-eg.com');

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertTrue(app(TrustedHostRegistry::class)->isTrusted('pharma-eg.com'));

        $this->assertSame(1, $queries, 'A cache miss must cost exactly one lookup, not a table scan.');
    }

    public function test_platform_hosts_are_resolved_without_any_io(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $registry = app(TrustedHostRegistry::class);
        $this->assertTrue($registry->isTrusted('anything.'.$base));
        $this->assertTrue($registry->isTrusted('localhost'));

        $this->assertSame(0, $queries, 'Platform hosts must match structurally, with no query.');
    }

    public function test_disabling_a_domain_immediately_removes_trust(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store, 'pharma-eg.com');
        $registry = app(TrustedHostRegistry::class);

        $this->assertTrue($registry->isTrusted('pharma-eg.com'));

        $this->domains->disable($domain);

        // Cache invalidation must be immediate — a disabled domain that stayed
        // trusted for the cache TTL would keep serving after being switched off.
        $this->assertFalse($registry->isTrusted('pharma-eg.com'));
        $this->assertNull(app(StoreDomainResolver::class)->resolve('pharma-eg.com'));
    }

    public function test_deleting_a_domain_immediately_removes_trust(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store, 'pharma-eg.com');
        $registry = app(TrustedHostRegistry::class);
        $this->assertTrue($registry->isTrusted('pharma-eg.com'));

        $this->domains->detach($domain);

        $this->assertFalse($registry->isTrusted('pharma-eg.com'));
    }

    // =====================================================================
    // Cache poisoning
    // =====================================================================

    public function test_resolution_cache_is_keyed_per_host_and_cannot_be_crossed(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');
        $this->verifiedDomain($a, 'pharma-eg.com');
        $this->verifiedDomain($b, 'myshop.net');

        $resolver = app(StoreDomainResolver::class);

        // Warm both, then confirm neither entry can serve the other's store.
        $this->assertSame((int) $a->id, (int) $resolver->resolve('pharma-eg.com')->id);
        $this->assertSame((int) $b->id, (int) $resolver->resolve('myshop.net')->id);
        $this->assertSame((int) $a->id, (int) $resolver->resolve('pharma-eg.com')->id);
    }

    public function test_a_rejected_host_is_not_cached_as_a_valid_resolution(): void
    {
        $store = $this->makeStore();
        $this->verifiedDomain($store, 'pharma-eg.com');
        $resolver = app(StoreDomainResolver::class);

        // A malformed variant must never populate the legitimate host's entry.
        $this->assertNull($resolver->resolve('pharma-eg.com:8080@evil.io'));
        $this->assertSame((int) $store->id, (int) $resolver->resolve('pharma-eg.com')->id);
    }

    // =====================================================================
    // Verification integrity
    // =====================================================================

    public function test_verification_uses_the_domains_own_challenge_name(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $seen = [];
        $this->swap(DnsTxtLookup::class, new class($seen) extends DnsTxtLookup
        {
            public array $seen = [];

            public function __construct(array $seen)
            {
                $this->seen = $seen;
            }

            public function txt(string $name): array
            {
                $this->seen[] = $name;

                return [];
            }
        });

        $lookup = app(DnsTxtLookup::class);
        app(StoreDomainService::class)->checkDns($domain);

        $this->assertContains('_sellchase-challenge.pharma-eg.com', $lookup->seen);
    }

    // =====================================================================
    // Notifications: recipients, channels, no duplicates
    // =====================================================================

    public function test_repeated_verification_does_not_re_notify(): void
    {
        \Notification::fake();

        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->swap(DnsTxtLookup::class, new class($domain->verificationTxtValue()) extends DnsTxtLookup
        {
            public function __construct(private ?string $value) {}

            public function txt(string $name): array
            {
                return [$this->value];
            }
        });
        $service = app(StoreDomainService::class);

        // The daily sweep re-verifies constantly; only the first transition is
        // an event worth telling the owner about.
        $service->verify($domain->refresh());
        $service->verify($domain->refresh());
        $service->verify($domain->refresh());

        \Notification::assertSentToTimes(
            $store->owner,
            DomainVerifiedNotification::class,
            1,
        );
    }

    public function test_notifications_go_to_the_store_owner_only(): void
    {
        \Notification::fake();

        $store = $this->makeStore('nike');
        $other = $this->makeStore('adidas');

        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->domains->disable($domain->refresh());

        \Notification::assertSentTo($store->owner, DomainDisabledNotification::class);
        \Notification::assertNotSentTo($other->owner, DomainDisabledNotification::class);
    }

    public function test_domain_notifications_use_the_configured_channels(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);

        $channels = (new DomainVerifiedNotification($domain))->via($store->owner);

        // Resolved through the existing preference system, not hardcoded.
        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
    }

    public function test_a_domain_cannot_be_made_primary_for_another_store(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');
        $victim = $this->verifiedDomain($b, 'myshop.net');

        $this->domains->makePrimary($victim);

        // Promotion must not have touched store A's domains at all.
        $this->assertSame(0, $a->domains()->where('host', 'myshop.net')->count());
        $this->assertSame(1, $b->domains()->where('is_primary', true)->count());
        $this->assertSame(1, $a->domains()->where('is_primary', true)->count());
    }
}
