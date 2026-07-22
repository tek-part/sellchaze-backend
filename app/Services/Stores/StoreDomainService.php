<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Models\User;
use App\Notifications\DomainDisabledNotification;
use App\Notifications\DomainPrimaryChangedNotification;
use App\Notifications\DomainVerificationFailedNotification;
use App\Notifications\DomainVerifiedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single writer for tenant CUSTOM domains.
 *
 * Ownership invariants enforced here (see also the `host` UNIQUE index):
 *  - A host belongs to exactly one store, ever. Attaching a host another store
 *    already holds is rejected — never silently re-pointed.
 *  - At most one primary domain per store, so the 301 target
 *    (StoreDomainResolver::canonicalHostFor) and the canonical tag
 *    (StorefrontUrlGenerator::publicHost) can never disagree.
 *  - A host only becomes servable after DNS ownership is proven.
 *
 * Every mutation runs in a transaction with the store row locked, so concurrent
 * requests cannot interleave into a double-primary or a split ownership state.
 */
class StoreDomainService
{
    public function __construct(
        private readonly StoreDomainResolver $resolver,
        private readonly DnsTxtLookup $dns,
        private readonly DomainAuditLogger $audit,
        private readonly DomainNotifier $notifier,
    ) {}

    // ------------------------------------------------------------ normalising

    /**
     * Lowercase, trim, strip scheme/path/port/trailing dot, and IDN-encode.
     * Reuses the resolver's normalisation so writes and reads agree exactly.
     */
    public function normalize(string $host): string
    {
        $host = trim($host);

        // Tolerate a pasted URL ("https://shop.brand.com/") — take the host only.
        if (str_contains($host, '://')) {
            $host = (string) (parse_url($host, PHP_URL_HOST) ?: '');
        }
        $host = ltrim($host, '/');
        $host = explode('/', $host)[0];

        // Unicode domains are stored in their ASCII (punycode) form, which is what
        // the Host header actually carries.
        if ($host !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        return (string) $this->resolver->normalizeHost($host);
    }

    // ------------------------------------------------------------- validating

    /**
     * Reject anything that is not a hostname a tenant may legitimately claim.
     *
     * @throws ValidationException
     */
    public function assertValidHost(string $host): void
    {
        $fail = static function (string $message): never {
            throw ValidationException::withMessages(['host' => [$message]]);
        };

        if ($host === '') {
            $fail(__('A domain is required.'));
        }

        if (strlen($host) > 253) {
            $fail(__('That domain is too long.'));
        }

        // Must be a dotted hostname: labels of a-z 0-9 and inner hyphens, TLD >= 2 alpha.
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (! preg_match('/^(?:'.$label.'\.)+[a-z]{2,63}$/', $host)) {
            $fail(__('That is not a valid domain name.'));
        }

        // An IP literal is never an ownable domain.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $fail(__('An IP address cannot be used as a custom domain.'));
        }

        // Development-only hosts must never be connectable in production.
        if (app()->environment('production')) {
            $devSuffixes = ['localhost', '.localhost', '.local', '.test', '.invalid', '.example'];
            foreach ($devSuffixes as $suffix) {
                if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                    $fail(__('That domain cannot be used in production.'));
                }
            }
        }

        // The platform's own hosts are not tenant-claimable. This is the gap that
        // RESERVED_SLUGS does NOT cover: it only guards subdomain labels.
        foreach ($this->platformHosts() as $platformHost) {
            if ($platformHost === '') {
                continue;
            }
            if ($host === $platformHost || str_ends_with($host, '.'.$platformHost)) {
                $fail(__('That domain is reserved by the platform.'));
            }
        }
    }

    /**
     * Hosts the platform owns. Anything at or under these is off limits, so a
     * tenant can never claim sellchase.com, api.sellchase.com, or the app host.
     *
     * @return list<string>
     */
    private function platformHosts(): array
    {
        $hosts = [$this->resolver->baseDomain()];

        foreach (['app.url', 'sellchase.frontend_url'] as $key) {
            $url = (string) config($key, '');
            $parsed = $url === '' ? null : parse_url($url, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $hosts[] = strtolower($parsed);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    // ---------------------------------------------------------------- attach

    /**
     * Attach a custom domain to a store in the PENDING state.
     *
     * The domain is stored but is NOT servable and is NOT trusted until verified,
     * so attaching alone grants no ability to serve traffic on that host.
     *
     * @throws ValidationException
     */
    public function attach(Store $store, string $host, ?User $actor = null): StoreDomain
    {
        $host = $this->normalize($host);
        $this->assertValidHost($host);

        return DB::transaction(function () use ($store, $host, $actor): StoreDomain {
            // Lock the store so two concurrent attaches cannot both pass the checks.
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            // Abuse protection: cap domains per store to stop domain farming.
            $max = (int) config('sellchase.storefront.domains.max_per_store', 25);
            if ($store->domains()->where('type', 'custom')->count() >= $max) {
                throw ValidationException::withMessages([
                    'host' => [__('You have reached the maximum of :max custom domains.', ['max' => $max])],
                ]);
            }

            $existing = StoreDomain::query()->where('host', $host)->lockForUpdate()->first();

            if ($existing !== null) {
                if ((int) $existing->store_id !== (int) $store->id) {
                    // Ownership NEVER transfers implicitly. This is the anti-takeover rule.
                    // Audited against the CLAIMING store so attempted takeovers are visible.
                    $this->audit->record(
                        $existing,
                        StoreDomainEvent::OWNERSHIP_REJECTED,
                        $actor,
                        ['store_id' => $existing->store_id],
                        ['attempted_by_store_id' => $store->id],
                        'Domain is already connected to another store.',
                    );

                    throw ValidationException::withMessages([
                        'host' => [__('That domain is already connected to another store.')],
                    ]);
                }

                throw ValidationException::withMessages([
                    'host' => [__('That domain is already connected to this store.')],
                ]);
            }

            try {
                $domain = StoreDomain::create([
                    'store_id' => $store->id,
                    'host' => $host,
                    'type' => 'custom',
                    'status' => StoreDomain::STATUS_PENDING,
                    'is_primary' => false,
                    'verification_token' => $this->newToken(),
                    'verification_token_expires_at' => $this->tokenExpiry(),
                    'ssl_status' => StoreDomain::SSL_NONE,
                    'created_by_user_id' => $actor?->id,
                ]);

                $this->audit->record($domain, StoreDomainEvent::DOMAIN_ADDED, $actor, null, [
                    'host' => $host,
                    'type' => 'custom',
                ]);

                return $domain;
            } catch (QueryException $e) {
                // Lost a race against another transaction inserting the same host.
                if ($this->isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'host' => [__('That domain is already connected to another store.')],
                    ]);
                }
                throw $e;
            }
        });
    }

    // ---------------------------------------------------------- verification

    /**
     * Issue a fresh challenge token and return the domain to PENDING.
     *
     * Rotating the token invalidates any previously published TXT record, which
     * is what makes a captured challenge value non-replayable.
     *
     * @throws ValidationException when the domain is locked by abuse protection
     */
    public function startVerification(StoreDomain $domain, ?User $actor = null): StoreDomain
    {
        $this->assertNotLocked($domain);

        $domain->forceFill([
            'status' => StoreDomain::STATUS_PENDING,
            'verification_token' => $this->newToken(),
            'verification_token_expires_at' => $this->tokenExpiry(),
            'verified_at' => null,
            'last_error' => null,
            'last_checked_at' => null,
        ])->save();

        $this->audit->record($domain, StoreDomainEvent::VERIFICATION_STARTED, $actor);

        return $domain->refresh();
    }

    /**
     * Verification is locked after too many failed attempts, with a cooldown.
     * This is what stops verification brute-force and DNS-lookup amplification.
     *
     * @throws ValidationException
     */
    public function assertNotLocked(StoreDomain $domain): void
    {
        if ($domain->isLocked()) {
            throw ValidationException::withMessages([
                'host' => [__('Too many verification attempts. Try again after :time.', [
                    'time' => $domain->locked_until?->diffForHumans(),
                ])],
            ]);
        }
    }

    /** The DNS TXT record name the tenant must publish for this domain. */
    public function verificationRecordName(StoreDomain $domain): string
    {
        return StoreDomain::VERIFICATION_TXT_NAME.'.'.$domain->host;
    }

    /**
     * Look up the challenge TXT record. Pure check — records no state.
     *
     * An expired token never matches, so a TXT record left in place forever
     * cannot keep re-verifying a domain the tenant no longer controls.
     *
     * Multiple TXT records at the same name are normal (SPF, other vendors);
     * every value is compared and any exact match wins.
     */
    public function checkDns(StoreDomain $domain): bool
    {
        $expected = $domain->verificationTxtValue();
        if ($expected === null || $domain->tokenHasExpired()) {
            return false;
        }

        $found = $this->dns->txt($this->verificationRecordName($domain));

        foreach ($found as $value) {
            if (hash_equals($expected, trim($value, "\" \t\n\r\0\x0B"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the host point at us? Accepts either the configured CNAME target or
     * the configured A record — apex domains cannot use CNAME.
     *
     * @return array{ok: bool, type: ?string}
     */
    public function checkDnsTarget(StoreDomain $domain): array
    {
        $cnameTarget = $this->normalizeTarget((string) config('sellchase.storefront.domains.cname_target', ''));
        $aTarget = $this->normalizeTarget((string) config('sellchase.storefront.domains.a_target', ''));

        if ($cnameTarget !== '' && in_array($cnameTarget, $this->dns->cname($domain->host), true)) {
            return ['ok' => true, 'type' => 'CNAME'];
        }

        if ($aTarget !== '' && in_array($aTarget, $this->dns->a($domain->host), true)) {
            return ['ok' => true, 'type' => 'A'];
        }

        // Nothing configured to compare against — do not report a false failure.
        if ($cnameTarget === '' && $aTarget === '') {
            return ['ok' => true, 'type' => null];
        }

        return ['ok' => false, 'type' => null];
    }

    /**
     * Run the DNS check and record the outcome. Returns true when verified.
     *
     * Called from queued jobs only (VerifyDomainOwnershipJob) — never inline in
     * an HTTP request.
     */
    public function verify(StoreDomain $domain, ?User $actor = null): bool
    {
        if ($this->checkDns($domain)) {
            $this->markVerified($domain, $actor);

            return true;
        }

        $this->markFailed(
            $domain,
            $domain->tokenHasExpired()
                ? __('The verification token has expired. Start verification again to get a new one.')
                : __('The verification TXT record was not found.'),
            $actor,
        );

        return false;
    }

    public function markVerified(StoreDomain $domain, ?User $actor = null): StoreDomain
    {
        $wasVerified = $domain->status === StoreDomain::STATUS_VERIFIED;
        $previous = $domain->status;

        $domain->forceFill([
            'status' => StoreDomain::STATUS_VERIFIED,
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
            'dns_txt_ok' => true,
            // A successful verification clears the abuse counters.
            'verification_attempts' => 0,
            'locked_until' => null,
        ])->save();

        // Only announce a real transition — the daily sweep re-verifies constantly.
        if (! $wasVerified) {
            $this->audit->recordStatusChange(
                $domain,
                StoreDomainEvent::VERIFICATION_PASSED,
                $previous,
                StoreDomain::STATUS_VERIFIED,
                $actor,
            );
            $this->notifier->send($domain, new DomainVerifiedNotification($domain->fresh()));
        }

        return $domain->refresh();
    }

    /**
     * Record a failed check. A previously VERIFIED domain is NOT torn down by a
     * single transient DNS failure — it keeps serving and only the diagnostic
     * fields move, so a resolver blip cannot black-hole a live storefront.
     */
    public function markFailed(StoreDomain $domain, string $error, ?User $actor = null): StoreDomain
    {
        $previous = $domain->status;
        $attempts = $domain->verification_attempts + 1;
        $maxAttempts = (int) config('sellchase.storefront.domains.max_verification_attempts', 10);

        $attributes = [
            'last_checked_at' => now(),
            'last_error' => Str::limit($error, 490),
            'dns_txt_ok' => false,
            'verification_attempts' => $attempts,
        ];

        // A previously VERIFIED domain is not torn down by one failed check —
        // the daily sweep disables it only after repeated failures.
        if ($previous !== StoreDomain::STATUS_VERIFIED) {
            $attributes['status'] = StoreDomain::STATUS_REJECTED;
        }

        // Cooldown after repeated failures: blocks brute force and stops a
        // misconfigured domain from hammering DNS forever.
        if ($attempts >= $maxAttempts) {
            $attributes['locked_until'] = now()->addMinutes(
                (int) config('sellchase.storefront.domains.lock_minutes', 60),
            );
        }

        $domain->forceFill($attributes)->save();

        $this->audit->record($domain, StoreDomainEvent::VERIFICATION_FAILED, $actor, null, [
            'attempts' => $attempts,
        ], $error);

        // Notify only on the first failure of a run, not on every retry.
        if ($previous !== StoreDomain::STATUS_REJECTED) {
            $this->notifier->send($domain, new DomainVerificationFailedNotification($domain->fresh(), $error));
        }

        return $domain->refresh();
    }

    /** Take a domain out of service without deleting its history. */
    public function disable(StoreDomain $domain, ?User $actor = null, ?string $reason = null): StoreDomain
    {
        $previous = $domain->status;

        $updated = DB::transaction(function () use ($domain, $actor, $reason, $previous): StoreDomain {
            $domain->forceFill([
                'status' => StoreDomain::STATUS_DISABLED,
                'is_primary' => false,
            ])->save();

            $this->ensurePrimary($domain->store);

            $this->audit->recordStatusChange(
                $domain,
                StoreDomainEvent::DISABLED,
                $previous,
                StoreDomain::STATUS_DISABLED,
                $actor,
                $reason,
            );

            return $domain->refresh();
        });

        if ($previous !== StoreDomain::STATUS_DISABLED) {
            $this->notifier->send($updated, new DomainDisabledNotification(
                $updated,
                $reason ?? __('It was disabled from your store settings.'),
            ));
        }

        return $updated;
    }

    /**
     * Bring a disabled domain back. It returns to PENDING, never straight to
     * verified — ownership must be re-proven before it can serve again.
     */
    public function enable(StoreDomain $domain, ?User $actor = null): StoreDomain
    {
        $previous = $domain->status;

        $domain->forceFill([
            'status' => StoreDomain::STATUS_PENDING,
            'verification_token' => $this->newToken(),
            'verification_token_expires_at' => $this->tokenExpiry(),
            'verification_attempts' => 0,
            'locked_until' => null,
            'last_error' => null,
        ])->save();

        $this->audit->recordStatusChange(
            $domain,
            StoreDomainEvent::ENABLED,
            $previous,
            StoreDomain::STATUS_PENDING,
            $actor,
        );

        return $domain->refresh();
    }

    // -------------------------------------------------------------- primary

    /**
     * Promote a verified domain to the store's single primary host.
     *
     * Every other domain for the store is demoted to a secondary/alias row — kept,
     * never deleted, so old hosts keep 301-ing to the new primary and accumulated
     * SEO equity is preserved.
     *
     * @throws ValidationException
     */
    public function makePrimary(StoreDomain $domain, ?User $actor = null): StoreDomain
    {
        if (! $domain->isServable()) {
            throw ValidationException::withMessages([
                'host' => [__('A domain must be verified before it can be made primary.')],
            ]);
        }

        [$updated, $previousHost] = DB::transaction(function () use ($domain, $actor): array {
            Store::query()->whereKey($domain->store_id)->lockForUpdate()->first();

            $previousHost = null;

            StoreDomain::query()
                ->where('store_id', $domain->store_id)
                ->where('id', '!=', $domain->id)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->get()
                ->each(function (StoreDomain $other) use (&$previousHost): void {
                    $previousHost ??= $other->host;
                    $other->is_primary = false;
                    $other->save(); // model save -> resolver cache invalidation
                });

            $domain->is_primary = true;
            $domain->save();

            $this->audit->record(
                $domain,
                StoreDomainEvent::PRIMARY_CHANGED,
                $actor,
                ['host' => $previousHost],
                ['host' => $domain->host],
            );

            return [$domain->refresh(), $previousHost];
        });

        $this->notifier->send($updated, new DomainPrimaryChangedNotification($updated, $previousHost));

        return $updated;
    }

    /**
     * Guarantee the store still has exactly one primary after a demotion/removal,
     * preferring a verified custom domain, else the platform subdomain.
     */
    public function ensurePrimary(?Store $store): void
    {
        if ($store === null) {
            return;
        }

        $hasPrimary = $store->servableDomains()->where('is_primary', true)->exists();
        if ($hasPrimary) {
            return;
        }

        $fallback = $store->servableDomains()
            ->orderByRaw("CASE WHEN type = 'custom' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();

        if ($fallback !== null) {
            $fallback->is_primary = true;
            $fallback->save();
        }
    }

    // --------------------------------------------------------------- detach

    /** Remove a custom domain entirely. The platform subdomain can never be detached. */
    public function detach(StoreDomain $domain, ?User $actor = null): void
    {
        if (! $domain->isCustom()) {
            throw ValidationException::withMessages([
                'host' => [__('The platform subdomain cannot be removed.')],
            ]);
        }

        DB::transaction(function () use ($domain, $actor): void {
            $store = $domain->store;

            // Audit BEFORE deletion so the trail is complete; the event keeps a
            // denormalised host and a nullOnDelete FK, so it survives the delete.
            $this->audit->record($domain, StoreDomainEvent::DOMAIN_REMOVED, $actor, [
                'host' => $domain->host,
                'status' => $domain->status,
                'was_primary' => $domain->is_primary,
            ], null);

            $domain->delete();
            $this->ensurePrimary($store);
        });
    }

    // ------------------------------------------------------------------ ssl

    /**
     * Record SSL state for a domain.
     *
     * Provider-agnostic by design: Let's Encrypt (certbot/ACME), Cloudflare for
     * SaaS, or a reverse proxy doing on-demand TLS all report through this one
     * hook. The platform never issues certificates itself.
     */
    public function recordSslStatus(
        StoreDomain $domain,
        string $status,
        ?string $provider = null,
        ?\DateTimeInterface $issuedAt = null,
        ?\DateTimeInterface $expiresAt = null,
    ): StoreDomain {
        if (! in_array($status, StoreDomain::SSL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'ssl_status' => [__('Unknown SSL status.')],
            ]);
        }

        $domain->forceFill(array_filter([
            'ssl_status' => $status,
            'ssl_provider' => $provider,
            'ssl_issued_at' => $issuedAt,
            'ssl_expires_at' => $expiresAt,
        ], static fn ($value) => $value !== null))->save();

        return $domain->refresh();
    }

    /**
     * Hosts a TLS provider is allowed to issue a certificate for.
     *
     * This is the allow-callback an on-demand-TLS proxy (Caddy, Traefik) should
     * consult, so issuance is never attempted for a host we do not serve.
     */
    public function isIssuableHost(string $host): bool
    {
        return $this->resolver->resolveContext($host) !== null;
    }

    // -------------------------------------------------------------- internals

    private function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    private function tokenExpiry(): Carbon
    {
        return now()->addHours((int) config('sellchase.storefront.domains.token_ttl_hours', 168));
    }

    private function normalizeTarget(string $target): string
    {
        return strtolower(rtrim(trim($target), '.'));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 cover MySQL and Postgres unique-constraint violations.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
