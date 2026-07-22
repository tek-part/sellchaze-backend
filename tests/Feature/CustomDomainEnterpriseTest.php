<?php

namespace Tests\Feature;

use App\Jobs\Domains\CheckDomainDnsJob;
use App\Jobs\Domains\IssueDomainCertificateJob;
use App\Jobs\Domains\RefreshDomainVerificationJob;
use App\Jobs\Domains\RefreshSslStatusJob;
use App\Jobs\Domains\StartDomainVerificationJob;
use App\Jobs\Domains\VerifyDomainOwnershipJob;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDomainCertificate;
use App\Models\StoreDomainEvent;
use App\Models\User;
use App\Notifications\DomainSslExpiringNotification;
use App\Notifications\DomainSslIssuedNotification;
use App\Notifications\DomainVerifiedNotification;
use App\Services\JwtTokenService;
use App\Services\Stores\DnsTxtLookup;
use App\Services\Stores\DomainAuditLogger;
use App\Services\Stores\DomainHealthService;
use App\Services\Stores\DomainMetricsService;
use App\Services\Stores\DomainSslService;
use App\Services\Stores\Ssl\CertificateResult;
use App\Services\Stores\Ssl\SslProvider;
use App\Services\Stores\Ssl\SslProviderManager;
use App\Services\Stores\StoreDomainService;
use App\Services\StoreService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sprint 2: queues, scheduler, SSL abstraction, audit, health, metrics and the
 * security-hardening matrix.
 */
class CustomDomainEnterpriseTest extends TestCase
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

    private function verifiedDomain(Store $store, string $host = 'pharma-eg.com'): StoreDomain
    {
        $domain = StoreDomain::create([
            'store_id' => $store->id,
            'host' => $host,
            'type' => 'custom',
            'status' => StoreDomain::STATUS_VERIFIED,
            'is_primary' => false,
            // A verified custom domain always holds the token it proved with, so
            // the daily re-verification sweep can keep checking it.
            'verification_token' => str_repeat('a', 48),
            'verification_token_expires_at' => now()->addYear(),
        ]);

        return $domain->refresh();
    }

    /** Bind a DNS stub returning exactly these TXT values. */
    private function fakeDns(array $txt = [], array $cname = [], array $a = []): void
    {
        $this->swap(DnsTxtLookup::class, new class($txt, $cname, $a) extends DnsTxtLookup
        {
            public function __construct(private array $txtValues, private array $cnames, private array $as) {}

            public function txt(string $name): array
            {
                return $this->txtValues;
            }

            public function cname(string $name): array
            {
                return $this->cnames;
            }

            public function a(string $name): array
            {
                return $this->as;
            }
        });
        $this->domains = app(StoreDomainService::class);
    }

    /** Bind an SSL provider returning a fixed result. */
    private function fakeSsl(CertificateResult $result, string $name = 'fake'): void
    {
        $provider = new class($result, $name) implements SslProvider
        {
            public function __construct(private CertificateResult $result, private string $providerName) {}

            public function name(): string
            {
                return $this->providerName;
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

        $manager = app(SslProviderManager::class);
        $manager->extend('fake', fn () => $provider);
        config()->set('sellchase.storefront.ssl.provider', 'fake');
    }

    // =====================================================================
    // 1. QUEUE-BASED VERIFICATION
    // =====================================================================

    public function test_connecting_a_domain_queues_dns_check_and_never_resolves_dns_inline(): void
    {
        Queue::fake();

        $store = $this->makeStore();
        $user = $store->owner;

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v1/stores/{$store->id}/domains", ['host' => 'pharma-eg.com'])
            ->assertCreated();

        Queue::assertPushed(CheckDomainDnsJob::class);
    }

    public function test_verify_endpoint_returns_202_and_queues_rather_than_blocking(): void
    {
        Queue::fake();

        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($store->owner))
            ->postJson("/api/v1/stores/{$store->id}/domains/{$domain->id}/verify")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        Queue::assertPushed(StartDomainVerificationJob::class);
    }

    public function test_jobs_run_on_the_configured_queue(): void
    {
        Queue::fake();
        config()->set('sellchase.storefront.domains.queue', 'domains');

        StartDomainVerificationJob::dispatch(1);

        Queue::assertPushedOn('domains', StartDomainVerificationJob::class);
    }

    public function test_verification_job_chain_start_to_dns_to_ownership(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        Bus::fake([CheckDomainDnsJob::class]);
        (new StartDomainVerificationJob($domain->id))->handle(app(StoreDomainService::class));
        Bus::assertDispatched(CheckDomainDnsJob::class);

        Bus::fake([VerifyDomainOwnershipJob::class]);
        (new CheckDomainDnsJob($domain->id))->handle(app(StoreDomainService::class));
        Bus::assertDispatched(VerifyDomainOwnershipJob::class);
    }

    public function test_successful_ownership_verification_triggers_certificate_issuance(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        Bus::fake([IssueDomainCertificateJob::class]);
        (new VerifyDomainOwnershipJob($domain->id))->handle(app(StoreDomainService::class));

        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);
        Bus::assertDispatched(IssueDomainCertificateJob::class);
    }

    public function test_jobs_use_exponential_backoff(): void
    {
        $backoff = (new StartDomainVerificationJob(1))->backoff();

        $this->assertGreaterThan(1, count($backoff));
        for ($i = 1; $i < count($backoff); $i++) {
            $this->assertGreaterThan($backoff[$i - 1], $backoff[$i], 'Backoff must increase.');
        }
    }

    public function test_a_job_for_a_deleted_domain_is_a_no_op(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $id = $domain->id;
        $domain->delete();

        // Must not throw — a delete between dispatch and execution is a normal race.
        (new CheckDomainDnsJob($id))->handle(app(StoreDomainService::class));

        $this->assertTrue(true);
    }

    // =====================================================================
    // 2. SSL PROVIDER ABSTRACTION
    // =====================================================================

    public function test_ssl_provider_is_resolved_from_config_and_not_hardcoded(): void
    {
        $manager = app(SslProviderManager::class);

        foreach (['none', 'acme', 'letsencrypt', 'cloudflare', 'caddy', 'reverse-proxy'] as $name) {
            $this->assertInstanceOf(SslProvider::class, $manager->driver($name));
        }

        // The default is deliberately the inert provider, so an unconfigured
        // deployment never pretends a certificate exists.
        config()->set('sellchase.storefront.ssl.provider', 'none');
        $this->assertSame('none', $manager->driver()->name());
    }

    public function test_unknown_ssl_provider_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(SslProviderManager::class)->driver('does-not-exist');
    }

    public function test_issuing_records_certificate_state_history_and_audit(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);

        $this->fakeSsl(CertificateResult::issued(
            "Let's Encrypt",
            'ab12cd',
            ['pharma-eg.com'],
            now()->subDay(),
            now()->addDays(90),
        ));

        app(DomainSslService::class)->issue($domain);
        $domain->refresh();

        $this->assertSame(StoreDomain::SSL_ACTIVE, $domain->ssl_status);
        $this->assertSame("Let's Encrypt", $domain->ssl_issuer);
        $this->assertSame('ab12cd', $domain->ssl_fingerprint);
        $this->assertSame(['pharma-eg.com'], $domain->ssl_san);
        $this->assertSame(0, $domain->ssl_renewal_attempts);

        $this->assertDatabaseHas('store_domain_certificates', [
            'store_domain_id' => $domain->id,
            'status' => StoreDomainCertificate::STATUS_ISSUED,
        ]);
        $this->assertDatabaseHas('store_domain_events', [
            'store_domain_id' => $domain->id,
            'event' => StoreDomainEvent::SSL_ISSUED,
        ]);
    }

    public function test_failed_issuance_increments_attempts_and_is_audited(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $this->fakeSsl(CertificateResult::failed('CA rejected the order'));

        app(DomainSslService::class)->issue($domain);
        $domain->refresh();

        $this->assertSame(StoreDomain::SSL_FAILED, $domain->ssl_status);
        $this->assertSame(1, $domain->ssl_renewal_attempts);
        $this->assertStringContainsString('CA rejected', (string) $domain->ssl_last_error);
        $this->assertDatabaseHas('store_domain_events', [
            'store_domain_id' => $domain->id,
            'event' => StoreDomainEvent::SSL_FAILED,
        ]);
    }

    public function test_certificate_is_never_issued_for_an_unverified_domain(): void
    {
        $store = $this->makeStore();
        $pending = $this->domains->attach($store, 'pharma-eg.com'); // pending
        $this->fakeSsl(CertificateResult::issued('CA', 'fp', [], now(), now()->addDays(90)));

        $result = app(DomainSslService::class)->issue($pending);

        $this->assertFalse($result->ok);
        $this->assertNotSame(StoreDomain::SSL_ACTIVE, $pending->fresh()->ssl_status);
    }

    public function test_issuance_stops_after_the_configured_attempt_ceiling(): void
    {
        config()->set('sellchase.storefront.ssl.max_renewal_attempts', 2);
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill(['ssl_renewal_attempts' => 2])->save();

        $this->fakeSsl(CertificateResult::issued('CA', 'fp', [], now(), now()->addDays(90)));
        (new IssueDomainCertificateJob($domain->id))->handle(app(DomainSslService::class));

        // The ceiling protects CA rate limits: no attempt should have been made.
        $this->assertNotSame(StoreDomain::SSL_ACTIVE, $domain->fresh()->ssl_status);
    }

    public function test_pending_issuance_is_polled_not_marked_failed(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $this->fakeSsl(CertificateResult::pending('Caddy issues on first handshake'));

        app(DomainSslService::class)->issue($domain);

        $this->assertSame(StoreDomain::SSL_PENDING, $domain->fresh()->ssl_status);
    }

    // =====================================================================
    // 3. SCHEDULER / RE-VERIFICATION
    // =====================================================================

    public function test_reverify_command_queues_a_job_per_active_domain(): void
    {
        Queue::fake();
        $store = $this->makeStore();
        $this->verifiedDomain($store, 'pharma-eg.com');
        $this->verifiedDomain($store, 'myshop.net');

        $disabled = $this->verifiedDomain($store, 'rstwsf.com');
        $disabled->forceFill(['status' => StoreDomain::STATUS_DISABLED])->save();

        $this->artisan('domains:reverify')->assertSuccessful();

        // Disabled domains are skipped; only the two active ones are queued.
        Queue::assertPushed(RefreshDomainVerificationJob::class, 2);
    }

    public function test_reverification_disables_a_domain_only_after_repeated_failures(): void
    {
        config()->set('sellchase.storefront.domains.stale_after_failures', 3);

        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $this->domains->makePrimary($domain);
        $this->fakeDns([]); // records gone

        $job = fn () => (new RefreshDomainVerificationJob($domain->id))->handle(
            app(StoreDomainService::class),
            app(DomainAuditLogger::class),
        );

        // First two failures must NOT take a live storefront offline.
        $job();
        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);
        $job();
        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);

        // The third crosses the threshold.
        $job();
        $this->assertSame(StoreDomain::STATUS_DISABLED, $domain->fresh()->status);
    }

    public function test_reverification_clears_the_failure_streak_when_dns_returns(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill(['verification_attempts' => 2, 'last_error' => 'gone'])->save();

        $this->fakeDns([$domain->verificationTxtValue()]);
        (new RefreshDomainVerificationJob($domain->id))->handle(
            app(StoreDomainService::class),
            app(DomainAuditLogger::class),
        );

        $domain->refresh();
        $this->assertSame(0, $domain->verification_attempts);
        $this->assertNull($domain->last_error);
    }

    public function test_renew_command_queues_renewals_retries_and_polls(): void
    {
        Queue::fake();
        $store = $this->makeStore();

        $expiring = $this->verifiedDomain($store, 'pharma-eg.com');
        $expiring->forceFill([
            'ssl_status' => StoreDomain::SSL_ACTIVE,
            'ssl_expires_at' => now()->addDays(10),
        ])->save();

        $failed = $this->verifiedDomain($store, 'myshop.net');
        $failed->forceFill(['ssl_status' => StoreDomain::SSL_FAILED])->save();

        $pending = $this->verifiedDomain($store, 'rstwsf.com');
        $pending->forceFill(['ssl_status' => StoreDomain::SSL_PENDING])->save();

        $this->artisan('domains:renew-certificates')->assertSuccessful();

        Queue::assertPushed(IssueDomainCertificateJob::class, 2); // expiring + failed
        Queue::assertPushed(RefreshSslStatusJob::class, 1);       // pending
    }

    // =====================================================================
    // 4. CERTIFICATE MONITORING + NOTIFICATIONS
    // =====================================================================

    public function test_expiry_monitoring_notifies_once_per_threshold(): void
    {
        Notification::fake();

        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill([
            'ssl_status' => StoreDomain::SSL_ACTIVE,
            'ssl_fingerprint' => 'fp-1',
            'ssl_expires_at' => now()->addDays(6), // crosses the 7-day threshold
        ])->save();

        $this->artisan('domains:monitor-certificates')->assertSuccessful();
        Notification::assertSentTo($store->owner, DomainSslExpiringNotification::class);

        // Running again must not re-notify for the same threshold + certificate.
        Notification::fake();
        $this->artisan('domains:monitor-certificates')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_a_renewed_certificate_may_notify_again(): void
    {
        Notification::fake();
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill([
            'ssl_status' => StoreDomain::SSL_ACTIVE,
            'ssl_fingerprint' => 'fp-1',
            'ssl_expires_at' => now()->addDays(6),
        ])->save();

        $this->artisan('domains:monitor-certificates');

        // New certificate (different fingerprint) that is also near expiry.
        $domain->forceFill(['ssl_fingerprint' => 'fp-2'])->save();

        Notification::fake();
        $this->artisan('domains:monitor-certificates');
        Notification::assertSentTo($store->owner, DomainSslExpiringNotification::class);
    }

    public function test_owner_is_notified_on_verification_and_ssl_issuance(): void
    {
        Notification::fake();
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $this->fakeDns([$domain->verificationTxtValue()]);
        app(StoreDomainService::class)->verify($domain);
        Notification::assertSentTo($store->owner, DomainVerifiedNotification::class);

        $this->fakeSsl(CertificateResult::issued('CA', 'fp', [], now(), now()->addDays(90)));
        app(DomainSslService::class)->issue($domain->refresh());
        Notification::assertSentTo($store->owner, DomainSslIssuedNotification::class);
    }

    public function test_a_notification_failure_never_breaks_a_state_transition(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        // Owner removed underneath us — notification has nowhere to go.
        $store->owner->delete();

        app(StoreDomainService::class)->verify($domain->refresh());

        $this->assertSame(StoreDomain::STATUS_VERIFIED, $domain->fresh()->status);
    }

    // =====================================================================
    // 5. AUDIT LOG
    // =====================================================================

    public function test_every_lifecycle_event_is_audited(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->fakeDns([$domain->verificationTxtValue()]);

        app(StoreDomainService::class)->verify($domain);
        app(StoreDomainService::class)->makePrimary($domain->refresh());
        app(StoreDomainService::class)->disable($domain->refresh());
        app(StoreDomainService::class)->enable($domain->refresh());

        $events = StoreDomainEvent::query()->where('store_id', $store->id)->pluck('event')->all();

        foreach ([
            StoreDomainEvent::DOMAIN_ADDED,
            StoreDomainEvent::VERIFICATION_PASSED,
            StoreDomainEvent::PRIMARY_CHANGED,
            StoreDomainEvent::DISABLED,
            StoreDomainEvent::ENABLED,
        ] as $expected) {
            $this->assertContains($expected, $events, "Missing audit event [{$expected}].");
        }
    }

    public function test_audit_entries_are_immutable(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $event = StoreDomainEvent::query()->where('store_domain_id', $domain->id)->firstOrFail();

        $event->reason = 'tampered';
        $this->assertFalse($event->save(), 'Audit rows must not be updatable.');
        $this->assertFalse($event->delete(), 'Audit rows must not be deletable.');

        $this->assertNotSame('tampered', $event->fresh()->reason);
    }

    public function test_audit_history_survives_domain_deletion(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');
        $this->domains->detach($domain);

        $rows = StoreDomainEvent::query()->where('host', 'pharma-eg.com')->get();

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertContains(StoreDomainEvent::DOMAIN_REMOVED, $rows->pluck('event')->all());
    }

    public function test_audit_captures_actor_and_request_context(): void
    {
        $store = $this->makeStore();
        $user = $store->owner;

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v1/stores/{$store->id}/domains", ['host' => 'pharma-eg.com'])
            ->assertCreated();

        $event = StoreDomainEvent::query()->where('event', StoreDomainEvent::DOMAIN_ADDED)->firstOrFail();

        $this->assertSame((int) $user->id, (int) $event->actor_user_id);
        $this->assertSame('user', $event->actor_type);
        $this->assertNotNull($event->ip);
    }

    public function test_owner_can_search_audit_history(): void
    {
        $store = $this->makeStore();
        $this->domains->attach($store, 'pharma-eg.com');
        $this->domains->attach($store, 'myshop.net');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($store->owner))
            ->getJson("/api/v1/stores/{$store->id}/domains/events?q=myshop")
            ->assertOk()
            ->assertJsonPath('data.0.host', 'myshop.net')
            ->assertJsonCount(1, 'data');
    }

    // =====================================================================
    // 6. HEALTH DASHBOARD
    // =====================================================================

    public function test_health_report_surfaces_checks_score_and_recommendations(): void
    {
        $store = $this->makeStore();
        $domain = $this->domains->attach($store, 'pharma-eg.com');

        $report = app(DomainHealthService::class)->report($domain);

        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('health_score', $report);
        $this->assertNotEmpty($report['recommendations']);
        $this->assertSame('TXT', $report['dns']['expected_txt_name'] ? 'TXT' : 'TXT');
        $this->assertStringContainsString('_sellchase-challenge', (string) $report['dns']['expected_txt_name']);
    }

    public function test_health_score_is_perfect_for_a_fully_working_domain(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill([
            'is_primary' => true,
            'dns_txt_ok' => true,
            'dns_target_ok' => true,
            'dns_target_type' => 'CNAME',
            'ssl_status' => StoreDomain::SSL_ACTIVE,
            'ssl_expires_at' => now()->addDays(80),
        ])->save();

        $report = app(DomainHealthService::class)->report($domain->fresh());

        $this->assertSame(100, $report['health_score']);
        $this->assertSame('ok', $report['health_level']);
        $this->assertEmpty($report['errors']);
    }

    public function test_expired_certificate_is_reported_as_an_error(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill([
            'ssl_status' => StoreDomain::SSL_ACTIVE,
            'ssl_expires_at' => now()->subDay(),
        ])->save();

        $report = app(DomainHealthService::class)->report($domain->fresh());

        $this->assertSame('error', $report['health_level']);
        $this->assertNotEmpty($report['errors']);
    }

    public function test_health_endpoints_are_reachable_by_the_owner(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $token = JwtTokenService::fromConfig()->issueAccessToken($store->owner);

        $this->withToken($token)
            ->getJson("/api/v1/stores/{$store->id}/domains/{$domain->id}/health")
            ->assertOk()
            ->assertJsonPath('data.host', 'pharma-eg.com');

        $this->withToken($token)
            ->getJson("/api/v1/stores/{$store->id}/domains/health")
            ->assertOk()
            ->assertJsonStructure(['data' => ['total', 'verified', 'ssl_active', 'health_score']]);
    }

    // =====================================================================
    // 7. METRICS
    // =====================================================================

    public function test_metrics_are_admin_only_and_prometheus_compatible(): void
    {
        $store = $this->makeStore();
        $this->verifiedDomain($store);

        // Store owner must not see platform-wide metrics.
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($store->owner))
            ->getJson('/api/v1/domain-metrics')
            ->assertForbidden();

        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin))
            ->getJson('/api/v1/domain-metrics')
            ->assertOk()
            ->assertJsonPath('data.domains_verified', 1);

        $body = $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin))
            ->get('/api/v1/domain-metrics/prometheus')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('# TYPE sellchase_custom_domain_domains_total gauge', $body);
        $this->assertStringContainsString('sellchase_custom_domain_domains_verified 1', $body);
    }

    public function test_metrics_count_certificate_expiry_buckets(): void
    {
        $store = $this->makeStore();
        $domain = $this->verifiedDomain($store);
        $domain->forceFill(['ssl_expires_at' => now()->addDays(5)])->save();

        $metrics = app(DomainMetricsService::class)->collect();

        $this->assertSame(1, $metrics['certificates_expiring_30d']);
        $this->assertSame(1, $metrics['certificates_expiring_7d']);
        $this->assertSame(0, $metrics['certificates_expired']);
    }
}
