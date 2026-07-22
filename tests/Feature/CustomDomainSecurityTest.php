<?php

namespace Tests\Feature;

use App\Jobs\Domains\IssueDomainCertificateJob;
use App\Jobs\Domains\VerifyDomainOwnershipJob;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Stores\DnsTxtLookup;
use App\Services\Stores\DomainSslService;
use App\Services\Stores\Ssl\CertificateResult;
use App\Services\Stores\Ssl\SslProvider;
use App\Services\Stores\Ssl\SslProviderManager;
use App\Services\Stores\StoreDomainResolver;
use App\Services\Stores\StoreDomainService;
use App\Services\Stores\TrustedHostRegistry;
use App\Services\StoreService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Security hardening matrix for custom domains.
 *
 * Each test names a specific attack and asserts the control that defeats it.
 */
class CustomDomainSecurityTest extends TestCase
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

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');

        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $this->stores->syncSubdomain($store);

        return $store;
    }

    private function fakeDns(array $txt): void
    {
        $this->swap(DnsTxtLookup::class, new class($txt) extends DnsTxtLookup
        {
            public function __construct(private array $values) {}

            public function txt(string $name): array
            {
                return $this->values;
            }
        });
        $this->domains = app(StoreDomainService::class);
    }

    // ------------------------------------------------ host header poisoning

    public function test_host_header_poisoning_cannot_select_a_store(): void
    {
        $store = $this->makeStore('nike');
        StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED, 'is_primary' => true,
        ]);

        $resolver = app(StoreDomainResolver::class);

        foreach ([
            'pharma-eg.com.attacker.example',   // suffix append
            'attacker.example',                 // unrelated
            'pharma-eg.com:8080@evil.io',       // userinfo confusion
            'PHARMA-EG.COM.evil.io',            // case + suffix
            'evil.io#pharma-eg.com',            // fragment confusion
        ] as $spoof) {
            $this->assertNull($resolver->resolve($spoof), "[$spoof] must not resolve.");
        }
    }

    public function test_untrusted_hosts_are_not_trusted_by_the_registry(): void
    {
        $store = $this->makeStore('nike');
        StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED, 'is_primary' => true,
        ]);

        $registry = app(TrustedHostRegistry::class);

        $this->assertTrue($registry->isTrusted('pharma-eg.com'));
        $this->assertFalse($registry->isTrusted('pharma-eg.com.attacker.example'));
        $this->assertFalse($registry->isTrusted('attacker.example'));
    }

    // ------------------------------------------------------- DNS rebinding

    public function test_dns_rebinding_does_not_grant_access_without_a_persisted_verified_row(): void
    {
        $this->makeStore('nike');

        // An attacker who can point ANY hostname at our IP still resolves to
        // nothing: routing is driven by the database, never by what DNS says.
        $resolver = app(StoreDomainResolver::class);

        $this->assertNull($resolver->resolve('rebound.attacker.example'));
        $this->assertNull($resolver->resolve('127.0.0.1.nip.io'));
    }

    public function test_a_private_ip_literal_can_never_be_registered(): void
    {
        $store = $this->makeStore('nike');

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254'] as $ip) {
            try {
                $this->domains->attach($store, $ip);
                $this->fail("[$ip] must be rejected.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    // --------------------------------------------- verification replay/expiry

    public function test_an_expired_token_cannot_verify_even_with_a_matching_txt_record(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        // The record is present and correct — but the challenge has expired.
        $this->fakeDns([$domain->verificationTxtValue()]);
        $domain->forceFill(['verification_token_expires_at' => now()->subMinute()])->save();

        $this->assertFalse($this->domains->verify($domain->refresh()));
        $this->assertNotSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);
    }

    public function test_a_rotated_token_invalidates_the_previously_published_record(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $oldValue = $domain->verificationTxtValue();

        // Owner restarts verification: a new token is issued.
        $this->domains->startVerification($domain);

        // The attacker still publishes the OLD challenge value.
        $this->fakeDns([$oldValue]);

        $this->assertFalse($this->domains->verify($domain->refresh()));
    }

    public function test_a_token_from_one_domain_cannot_verify_another(): void
    {
        $store = $this->makeStore('nike');
        $a = $this->domains->attach($store, 'pharma-eg.com');
        $b = $this->domains->attach($store, 'myshop.net');

        // Publish domain A's challenge while verifying domain B.
        $this->fakeDns([$a->verificationTxtValue()]);

        $this->assertFalse($this->domains->verify($b->refresh()));
    }

    public function test_duplicate_and_decoy_txt_records_are_handled_correctly(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        // Real-world zones carry many TXT records; the correct one must still win,
        // and near-miss decoys must not.
        $this->fakeDns([
            'v=spf1 include:_spf.google.com ~all',
            'sellchase-domain-verification=not-the-right-token',
            $domain->verificationTxtValue(),
            $domain->verificationTxtValue(), // duplicated by the DNS provider
        ]);

        $this->assertTrue($this->domains->verify($domain->refresh()));
    }

    public function test_a_near_miss_token_does_not_verify(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        // Prefix match only — must fail (comparison is exact, constant-time).
        $this->fakeDns([$domain->verificationTxtValue().'extra']);

        $this->assertFalse($this->domains->verify($domain->refresh()));
    }

    public function test_repeated_failures_lock_verification(): void
    {
        config()->set('sellchase.storefront.domains.max_verification_attempts', 3);

        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([]);

        for ($i = 0; $i < 3; $i++) {
            $this->domains->verify($domain->refresh());
        }

        $this->assertTrue($domain->fresh()->isLocked());

        $this->expectException(ValidationException::class);
        $this->domains->startVerification($domain->fresh());
    }

    // ------------------------------------------------------- queue replay

    public function test_replaying_a_verification_job_does_not_re_trigger_issuance(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        Queue::fake();

        // First run verifies and schedules issuance.
        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));
        Queue::assertPushed(IssueDomainCertificateJob::class, 1);

        // A replayed job must be idempotent — no second issuance, no CA abuse.
        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));
        Queue::assertPushed(IssueDomainCertificateJob::class, 1);
    }

    public function test_replaying_a_job_does_not_duplicate_audit_entries(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));
        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));
        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));

        $passed = StoreDomainEvent::query()
            ->where('store_domain_id', $domain->id)
            ->where('event', StoreDomainEvent::VERIFICATION_PASSED)
            ->count();

        $this->assertSame(1, $passed, 'Only a real transition should be audited.');
    }

    // ---------------------------------------------------- certificate replay

    public function test_a_certificate_for_a_different_host_is_still_recorded_against_its_own_domain(): void
    {
        $store = $this->makeStore('nike');
        $a = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);
        $b = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'myshop.net',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);

        $this->bindSsl(CertificateResult::issued('CA', 'shared-fingerprint', ['pharma-eg.com'], now(), now()->addDays(90)));

        app(DomainSslService::class)->issue($a->refresh());
        app(DomainSslService::class)->issue($b->refresh());

        // Each domain owns its own certificate row — state is never shared by
        // fingerprint, so a replayed certificate cannot silently cover a host.
        $this->assertSame(1, $a->certificates()->count());
        $this->assertSame(1, $b->certificates()->count());
        $this->assertNotSame((int) $a->id, (int) $b->certificates()->first()->store_domain_id);
    }

    public function test_certificate_cannot_be_farmed_via_unverified_domains(): void
    {
        Queue::fake();
        $store = $this->makeStore('nike');

        // Attach several domains without verifying any of them.
        foreach (['pharma-eg.com', 'myshop.net', 'rstwsf.com'] as $host) {
            $this->domains->attach($store, $host);
        }

        $this->artisan('domains:renew-certificates')->assertSuccessful();

        // Nothing is verified, so no certificate work is queued at all.
        Queue::assertNotPushed(IssueDomainCertificateJob::class);
    }

    // ------------------------------------------------------ race conditions

    public function test_concurrent_attach_of_the_same_host_yields_exactly_one_owner(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');

        $this->domains->attach($a, 'rstwsf.com');

        try {
            $this->domains->attach($b, 'rstwsf.com');
            $this->fail('Second attach must be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, StoreDomain::query()->where('host', 'rstwsf.com')->count());
        $this->assertSame(
            (int) $a->id,
            (int) StoreDomain::query()->where('host', 'rstwsf.com')->value('store_id'),
        );
    }

    public function test_repeated_promotion_never_leaves_two_primaries(): void
    {
        $store = $this->makeStore('nike');

        $one = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);
        $two = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'myshop.net',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);

        $this->domains->makePrimary($one->refresh());
        $this->domains->makePrimary($two->refresh());
        $this->domains->makePrimary($one->refresh());

        $this->assertSame(1, $store->domains()->where('is_primary', true)->count());
    }

    // ----------------------------------------------------- alias hijacking

    public function test_an_alias_cannot_be_hijacked_by_another_store(): void
    {
        $base = app(StoreDomainResolver::class)->baseDomain();

        $a = $this->makeStore('alpha');
        $this->stores->update($a, ['slug' => 'alpha-renamed']);

        $b = $this->makeStore('beta');
        $this->stores->update($b, ['slug' => 'alpha']); // tries to take the freed slug

        $this->assertSame(
            (int) $a->id,
            (int) StoreDomain::query()->where('host', 'alpha.'.$base)->value('store_id'),
            'The alias must still belong to the original store.',
        );
    }

    public function test_a_custom_primary_survives_a_slug_change(): void
    {
        $store = $this->makeStore('nike');
        $domain = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);
        $this->domains->makePrimary($domain->refresh());

        $this->stores->update($store, ['slug' => 'nike-renamed']);

        $this->assertTrue($domain->fresh()->is_primary);
    }

    // ---------------------------------------------------- cross-tenant access

    public function test_a_tenant_cannot_read_or_mutate_another_tenants_domain(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');

        $victim = StoreDomain::create([
            'store_id' => $b->id, 'host' => 'myshop.net',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);

        $token = JwtTokenService::fromConfig()->issueAccessToken($a->owner);

        // Addressing the victim's domain id under the ATTACKER's own store must
        // 404 — the lookup is scoped through the store relation.
        $this->withToken($token)
            ->getJson("/api/v1/stores/{$a->id}/domains/{$victim->id}/health")
            ->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/v1/stores/{$a->id}/domains/{$victim->id}/verify")
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/v1/stores/{$a->id}/domains/{$victim->id}")
            ->assertNotFound();

        // And addressing it under the victim's store id must be forbidden.
        $this->withToken($token)
            ->getJson("/api/v1/stores/{$b->id}/domains")
            ->assertForbidden();

        $this->assertDatabaseHas('store_domains', ['id' => $victim->id, 'store_id' => $b->id]);
    }

    public function test_audit_history_is_tenant_scoped(): void
    {
        $a = $this->makeStore('alpha');
        $b = $this->makeStore('beta');

        $this->domains->attach($a, 'pharma-eg.com');
        $this->domains->attach($b, 'myshop.net');

        $body = $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($a->owner))
            ->getJson("/api/v1/stores/{$a->id}/domains/events")
            ->assertOk()
            ->json('data');

        foreach ($body as $row) {
            $this->assertNotSame('myshop.net', $row['host'], 'Audit history leaked across tenants.');
        }
    }

    // ------------------------------------------------------- open redirect

    public function test_the_alias_redirect_target_is_never_user_controlled(): void
    {
        $store = $this->makeStore('nike');
        $primary = StoreDomain::create([
            'store_id' => $store->id, 'host' => 'pharma-eg.com',
            'type' => 'custom', 'status' => StoreDomain::STATUS_VERIFIED,
        ]);
        $this->domains->makePrimary($primary->refresh());

        $base = app(StoreDomainResolver::class)->baseDomain();

        // Attacker-supplied query data must not influence the redirect HOST —
        // the target comes from the database, and only path+query are echoed.
        $response = $this->get("http://nike.{$base}/products?next=https://evil.example")
            ->assertStatus(301);

        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('http://pharma-eg.com/', (string) $location);
        $this->assertStringNotContainsString('//evil.example', str_replace('https://evil.example', '', (string) $location));
    }

    // ------------------------------------------------------- rate limiting

    public function test_verification_endpoint_is_rate_limited_with_retry_after(): void
    {
        $store = $this->makeStore('nike');
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $token = JwtTokenService::fromConfig()->issueAccessToken($store->owner);

        $limited = null;
        for ($i = 0; $i < 12; $i++) {
            $response = $this->withToken($token)
                ->postJson("/api/v1/stores/{$store->id}/domains/{$domain->id}/verify");

            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }
        }

        $this->assertNotNull($limited, 'The verification endpoint must be rate limited.');
        $this->assertNotNull($limited->headers->get('Retry-After'));
    }

    public function test_domain_count_per_store_is_capped(): void
    {
        config()->set('sellchase.storefront.domains.max_per_store', 2);
        $store = $this->makeStore('nike');

        $this->domains->attach($store, 'pharma-eg.com');
        $this->domains->attach($store, 'myshop.net');

        $this->expectException(ValidationException::class);
        $this->domains->attach($store, 'rstwsf.com');
    }

    private function bindSsl(CertificateResult $result): void
    {
        $provider = new class($result) implements SslProvider
        {
            public function __construct(private CertificateResult $result) {}

            public function name(): string
            {
                return 'fake';
            }

            public function issue(StoreDomain $domain): CertificateResult
            {
                return $this->result;
            }

            public function renew(StoreDomain $domain): CertificateResult
            {
                return $this->result;
            }

            public function revoke(StoreDomain $domain): CertificateResult
            {
                return CertificateResult::revoked();
            }

            public function status(StoreDomain $domain): CertificateResult
            {
                return $this->result;
            }
        };

        app(SslProviderManager::class)->extend('fake', fn () => $provider);
        config()->set('sellchase.storefront.ssl.provider', 'fake');
    }
}
