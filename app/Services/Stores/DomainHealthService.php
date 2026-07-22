<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Services\Storefront\StorefrontUrlGenerator;

/**
 * Builds the health picture for a domain: what is working, what is not, and what
 * the owner should do about it.
 *
 * Reads only persisted state (populated by the scheduled jobs) — it performs no
 * DNS or TLS I/O itself, so it is safe to call from an HTTP request and cheap
 * enough to poll.
 */
class DomainHealthService
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly StorefrontUrlGenerator $urls,
        private readonly StoreDomainService $domains,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(StoreDomain $domain): array
    {
        $checks = $this->checks($domain);
        $score = $this->score($checks);

        return [
            'host' => $domain->host,
            'status' => $domain->status,
            'is_primary' => (bool) $domain->is_primary,
            'health_score' => $score,
            'health_level' => $this->level($score, $checks),
            'checks' => array_values($checks),
            'warnings' => $this->messages($checks, self::WARNING),
            'errors' => $this->messages($checks, self::ERROR),
            'recommendations' => $this->recommendations($domain, $checks),
            'canonical_url' => $this->urls->publicUrl($domain->store, '/'),
            'ssl' => [
                'status' => $domain->ssl_status,
                'provider' => $domain->ssl_provider,
                'issuer' => $domain->ssl_issuer,
                'fingerprint' => $domain->ssl_fingerprint,
                'san' => $domain->ssl_san,
                'issued_at' => $domain->ssl_issued_at,
                'expires_at' => $domain->ssl_expires_at,
                'days_remaining' => $domain->sslDaysRemaining(),
                'renewal_attempts' => $domain->ssl_renewal_attempts,
                'last_error' => $domain->ssl_last_error,
            ],
            'dns' => [
                'txt_ok' => (bool) $domain->dns_txt_ok,
                'target_ok' => (bool) $domain->dns_target_ok,
                'target_type' => $domain->dns_target_type,
                'expected_txt_name' => $domain->isCustom() ? $this->domains->verificationRecordName($domain) : null,
                'expected_txt_value' => $domain->verificationTxtValue(),
                'expected_cname' => config('sellchase.storefront.domains.cname_target'),
                'expected_a' => config('sellchase.storefront.domains.a_target'),
            ],
            'last_checked_at' => $domain->last_checked_at,
            'verified_at' => $domain->verified_at,
            'last_error' => $domain->last_error,
            'locked_until' => $domain->locked_until,
        ];
    }

    /**
     * Individual checks, each with a machine key so the UI can render its own copy.
     *
     * @return array<string, array{key: string, label: string, level: string, message: string}>
     */
    private function checks(StoreDomain $domain): array
    {
        $checks = [];

        // --- Ownership / TXT
        $checks['txt'] = $this->check('txt', 'TXT verification', match (true) {
            ! $domain->isCustom() => [self::OK, 'Platform subdomain — no DNS proof required.'],
            $domain->dns_txt_ok => [self::OK, 'Ownership TXT record found.'],
            $domain->tokenHasExpired() => [self::ERROR, 'The verification token has expired. Start verification again.'],
            default => [self::ERROR, 'Ownership TXT record not found.'],
        });

        // --- Traffic routing (CNAME or A)
        $checks['dns'] = $this->check('dns', 'DNS routing', match (true) {
            ! $domain->isCustom() => [self::OK, 'Platform subdomain resolves automatically.'],
            $domain->dns_target_ok && $domain->dns_target_type !== null => [self::OK, $domain->dns_target_type.' record points here.'],
            $domain->dns_target_ok => [self::UNKNOWN, 'No DNS target is configured on the platform to verify against.'],
            default => [self::ERROR, 'This domain does not point at our servers yet.'],
        });

        // --- Verification status
        $checks['verification'] = $this->check('verification', 'Verification', match ($domain->status) {
            StoreDomain::STATUS_VERIFIED => [self::OK, 'Domain ownership verified.'],
            StoreDomain::STATUS_PENDING => [self::WARNING, 'Verification is pending.'],
            StoreDomain::STATUS_REJECTED => [self::ERROR, $domain->last_error ?: 'Verification failed.'],
            default => [self::ERROR, 'Domain is disabled.'],
        });

        // --- Certificate
        $days = $domain->sslDaysRemaining();
        $checks['ssl'] = $this->check('ssl', 'SSL certificate', match (true) {
            $domain->ssl_status === StoreDomain::SSL_ACTIVE && $days !== null && $days <= 0 => [self::ERROR, 'The certificate has expired.'],
            $domain->ssl_status === StoreDomain::SSL_ACTIVE && $days !== null && $days <= 15 => [self::WARNING, "Certificate expires in {$days} days."],
            $domain->ssl_status === StoreDomain::SSL_ACTIVE => [self::OK, $days === null ? 'Certificate is active.' : "Certificate is active, {$days} days remaining."],
            $domain->ssl_status === StoreDomain::SSL_PENDING => [self::WARNING, 'Certificate issuance is in progress.'],
            $domain->ssl_status === StoreDomain::SSL_FAILED => [self::ERROR, $domain->ssl_last_error ?: 'Certificate issuance failed.'],
            default => [self::WARNING, 'No certificate yet.'],
        });

        // --- HTTPS reachability, inferred from certificate state
        $checks['https'] = $this->check('https', 'HTTPS', $domain->ssl_status === StoreDomain::SSL_ACTIVE
            ? [self::OK, 'HTTPS is serving.']
            : [self::WARNING, 'HTTPS is not active yet; visitors may see a security warning.']);

        // --- Canonical / redirect
        $checks['canonical'] = $this->check('canonical', 'Canonical URL', match (true) {
            $domain->is_primary => [self::OK, 'This is the primary domain; other domains redirect here.'],
            $domain->isServable() => [self::OK, 'Redirects to the primary domain.'],
            default => [self::WARNING, 'Not serving, so no redirect is active.'],
        });

        return $checks;
    }

    /**
     * @param  array{0: string, 1: string}  $outcome
     * @return array{key: string, label: string, level: string, message: string}
     */
    private function check(string $key, string $label, array $outcome): array
    {
        return ['key' => $key, 'label' => $label, 'level' => $outcome[0], 'message' => $outcome[1]];
    }

    /**
     * 0–100. Errors cost far more than warnings, and `unknown` is treated as
     * neutral so an unconfigured platform target does not fake a bad score.
     *
     * @param  array<string, array{level: string}>  $checks
     */
    private function score(array $checks): int
    {
        $counted = array_filter($checks, static fn (array $c): bool => $c['level'] !== self::UNKNOWN);
        if ($counted === []) {
            return 0;
        }

        $total = 0;
        foreach ($counted as $check) {
            $total += match ($check['level']) {
                self::OK => 100,
                self::WARNING => 55,
                default => 0,
            };
        }

        return (int) round($total / count($counted));
    }

    /** @param array<string, array{level: string}> $checks */
    private function level(int $score, array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['level'] === self::ERROR) {
                return self::ERROR;
            }
        }

        return $score >= 100 ? self::OK : self::WARNING;
    }

    /**
     * @param  array<string, array{level: string, message: string}>  $checks
     * @return list<string>
     */
    private function messages(array $checks, string $level): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['message'],
            array_filter($checks, static fn (array $c): bool => $c['level'] === $level),
        ));
    }

    /**
     * Actionable next steps, in the order the owner should do them.
     *
     * @param  array<string, array{level: string}>  $checks
     * @return list<string>
     */
    private function recommendations(StoreDomain $domain, array $checks): array
    {
        $out = [];

        if (($checks['dns']['level'] ?? null) === self::ERROR) {
            $cname = config('sellchase.storefront.domains.cname_target');
            $a = config('sellchase.storefront.domains.a_target');
            $out[] = $cname
                ? __('Point :host at :target with a CNAME record.', ['host' => $domain->host, 'target' => $cname])
                : __('Point :host at our servers.', ['host' => $domain->host]);
            if ($a) {
                $out[] = __('For an apex domain, use an A record to :ip instead.', ['ip' => $a]);
            }
        }

        if (($checks['txt']['level'] ?? null) === self::ERROR) {
            $out[] = __('Add the TXT record shown above, then run verification again.');
        }

        if ($domain->isLocked()) {
            $out[] = __('Verification is temporarily locked after repeated failures. It unlocks :time.', [
                'time' => $domain->locked_until?->diffForHumans(),
            ]);
        }

        if ($domain->ssl_status === StoreDomain::SSL_FAILED) {
            $out[] = __('Certificate issuance failed. Confirm DNS is correct, then retry SSL.');
        }

        if ($domain->isServable() && ! $domain->is_primary) {
            $out[] = __('Make this your primary domain so customers and search engines are sent here.');
        }

        return $out;
    }

    /**
     * Roll-up across a store's domains, for the dashboard header.
     *
     * @return array<string, mixed>
     */
    public function summaryForStore(Store $store): array
    {
        $domains = $store->domains()->get();

        $scores = [];
        $errors = 0;
        $warnings = 0;

        foreach ($domains as $domain) {
            $report = $this->report($domain);
            $scores[] = $report['health_score'];
            $errors += count($report['errors']);
            $warnings += count($report['warnings']);
        }

        return [
            'total' => $domains->count(),
            'custom' => $domains->where('type', 'custom')->count(),
            'verified' => $domains->where('status', StoreDomain::STATUS_VERIFIED)->count(),
            'pending' => $domains->where('status', StoreDomain::STATUS_PENDING)->count(),
            'rejected' => $domains->where('status', StoreDomain::STATUS_REJECTED)->count(),
            'disabled' => $domains->where('status', StoreDomain::STATUS_DISABLED)->count(),
            'ssl_active' => $domains->where('ssl_status', StoreDomain::SSL_ACTIVE)->count(),
            'ssl_failed' => $domains->where('ssl_status', StoreDomain::SSL_FAILED)->count(),
            'errors' => $errors,
            'warnings' => $warnings,
            'health_score' => $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores)),
            'primary_host' => $this->urls->publicHost($store),
        ];
    }
}
