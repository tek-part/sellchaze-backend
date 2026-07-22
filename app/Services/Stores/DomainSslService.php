<?php

namespace App\Services\Stores;

use App\Models\StoreDomain;
use App\Models\StoreDomainCertificate;
use App\Models\StoreDomainEvent;
use App\Notifications\DomainSslFailedNotification;
use App\Notifications\DomainSslIssuedNotification;
use App\Services\Stores\Ssl\CertificateResult;
use App\Services\Stores\Ssl\SslProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates certificate lifecycle across whichever provider is configured.
 *
 * Owns the *state machine* only — issuance itself belongs to an SslProvider, and
 * every call here is made from a queued job, never an HTTP request.
 *
 * Persists to three places, always together and in one transaction:
 *   - store_domains.ssl_*            current state (hot read path, no join)
 *   - store_domain_certificates      append-only history / renewal attempts
 *   - store_domain_events            audit trail
 */
class DomainSslService
{
    public function __construct(
        private readonly SslProviderManager $providers,
        private readonly DomainAuditLogger $audit,
        private readonly DomainNotifier $notifier,
    ) {}

    /** Request (or re-request) a certificate. */
    public function issue(StoreDomain $domain): CertificateResult
    {
        return $this->perform($domain, 'issue');
    }

    /** Renew an existing certificate. */
    public function renew(StoreDomain $domain): CertificateResult
    {
        return $this->perform($domain, 'renew');
    }

    /** Poll the provider for current state without attempting issuance. */
    public function refreshStatus(StoreDomain $domain): CertificateResult
    {
        $provider = $this->providers->driver();
        $result = $provider->status($domain);

        $this->applyResult($domain, $provider->name(), $result, renewal: false, recordHistory: false);

        return $result;
    }

    public function revoke(StoreDomain $domain): CertificateResult
    {
        $provider = $this->providers->driver();
        $result = $provider->revoke($domain);

        DB::transaction(function () use ($domain, $result, $provider): void {
            $domain->forceFill([
                'ssl_status' => StoreDomain::SSL_NONE,
                'ssl_fingerprint' => null,
                'ssl_expires_at' => null,
                'ssl_last_error' => $result->error,
            ])->save();

            $this->audit->record($domain, StoreDomainEvent::SSL_REVOKED, null, null, [
                'provider' => $provider->name(),
            ]);
        });

        return $result;
    }

    /**
     * Shared issue/renew path — identical bookkeeping, different provider verb.
     */
    private function perform(StoreDomain $domain, string $verb): CertificateResult
    {
        $provider = $this->providers->driver();

        // Never chase a certificate for a host we would not serve. This is the
        // same gate the on-demand-TLS ask endpoint uses, and it stops
        // certificate farming via unverified domains.
        if (! $domain->isServable()) {
            $result = CertificateResult::failed(
                'Domain must be verified before a certificate can be issued.',
                retryable: false,
            );
            $this->applyResult($domain, $provider->name(), $result, renewal: $verb === 'renew');

            return $result;
        }

        $domain->forceFill([
            'ssl_status' => StoreDomain::SSL_PENDING,
            'ssl_last_attempt_at' => now(),
        ])->save();

        $result = $verb === 'renew' ? $provider->renew($domain) : $provider->issue($domain);

        $this->applyResult($domain, $provider->name(), $result, renewal: $verb === 'renew');

        return $result;
    }

    /**
     * Persist a provider outcome: domain state + history + audit + notification.
     */
    private function applyResult(
        StoreDomain $domain,
        string $providerName,
        CertificateResult $result,
        bool $renewal,
        bool $recordHistory = true,
    ): void {
        $previousStatus = $domain->ssl_status;
        $previousFingerprint = $domain->ssl_fingerprint;

        DB::transaction(function () use ($domain, $providerName, $result, $renewal, $recordHistory, $previousStatus, $previousFingerprint): void {
            if ($result->ok && $result->status === 'active') {
                $domain->forceFill([
                    'ssl_status' => StoreDomain::SSL_ACTIVE,
                    'ssl_provider' => $providerName,
                    'ssl_issuer' => $result->issuer,
                    'ssl_fingerprint' => $result->fingerprint,
                    'ssl_san' => $result->san,
                    'ssl_issued_at' => $result->issuedAt,
                    'ssl_expires_at' => $result->expiresAt,
                    'ssl_renewal_attempts' => 0, // success clears the backoff counter
                    'ssl_last_error' => null,
                ])->save();
            } elseif ($result->status === 'pending') {
                $domain->forceFill([
                    'ssl_status' => StoreDomain::SSL_PENDING,
                    'ssl_provider' => $providerName,
                    'ssl_last_error' => $result->error,
                ])->save();
            } else {
                $domain->forceFill([
                    'ssl_status' => StoreDomain::SSL_FAILED,
                    'ssl_provider' => $providerName,
                    'ssl_renewal_attempts' => (int) $domain->ssl_renewal_attempts + 1,
                    'ssl_last_error' => Str::limit((string) $result->error, 490),
                ])->save();
            }

            if ($recordHistory) {
                StoreDomainCertificate::create([
                    'store_domain_id' => $domain->id,
                    'provider' => $providerName,
                    'status' => match (true) {
                        $result->ok && $result->status === 'active' => StoreDomainCertificate::STATUS_ISSUED,
                        $result->status === 'pending' => StoreDomainCertificate::STATUS_PENDING,
                        default => StoreDomainCertificate::STATUS_FAILED,
                    },
                    'issuer' => $result->issuer,
                    'fingerprint' => $result->fingerprint,
                    'san' => $result->san,
                    // Never null: a freshly created model may not have the DB
                    // default loaded into memory yet.
                    'attempt' => (int) $domain->ssl_renewal_attempts,
                    'issued_at' => $result->issuedAt,
                    'expires_at' => $result->expiresAt,
                    'error' => $result->error === null ? null : Str::limit($result->error, 490),
                ]);
            }

            // Audit only on a real transition, so polling does not spam the log.
            if ($result->ok && $result->status === 'active') {
                $changed = $previousStatus !== StoreDomain::SSL_ACTIVE
                    || $previousFingerprint !== $result->fingerprint;

                if ($changed) {
                    $this->audit->record(
                        $domain,
                        $renewal || $previousFingerprint !== null
                            ? StoreDomainEvent::SSL_RENEWED
                            : StoreDomainEvent::SSL_ISSUED,
                        null,
                        ['fingerprint' => $previousFingerprint, 'status' => $previousStatus],
                        ['fingerprint' => $result->fingerprint, 'issuer' => $result->issuer, 'expires_at' => $result->expiresAt?->format(DATE_ATOM)],
                    );
                }
            } elseif ($result->status === 'failed' && $previousStatus !== StoreDomain::SSL_FAILED) {
                $this->audit->record(
                    $domain,
                    StoreDomainEvent::SSL_FAILED,
                    null,
                    ['status' => $previousStatus],
                    ['status' => StoreDomain::SSL_FAILED],
                    $result->error,
                );
            }
        });

        // Notifications outside the transaction — a mail/queue failure must not
        // roll back certificate state we already know to be true.
        if ($result->ok && $result->status === 'active' && $previousStatus !== StoreDomain::SSL_ACTIVE) {
            $this->notifier->send($domain, new DomainSslIssuedNotification($domain->fresh(), $renewal));
        } elseif ($result->status === 'failed' && $previousStatus !== StoreDomain::SSL_FAILED) {
            $this->notifier->send($domain, new DomainSslFailedNotification($domain->fresh(), (string) $result->error));
        }
    }
}
