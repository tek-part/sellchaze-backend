<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;
use Illuminate\Support\Facades\Process;

/**
 * ACME issuance driven by an external client (certbot, lego, acme.sh).
 *
 * Let's Encrypt is the usual CA here but nothing about this class assumes it —
 * the directory URL and the command template are configuration. Pointing at
 * ZeroSSL, Buypass or an internal ACME CA is a config change, not a code change.
 *
 * The command template supports {host} and {email} placeholders, e.g.
 *   certbot certonly --webroot -w /var/www/html -d {host} -m {email} -n --agree-tos
 *
 * Runs only from queued jobs — never in an HTTP request — and reports real state
 * by probing the edge afterwards rather than trusting the exit code alone.
 */
class AcmeSslProvider implements SslProvider
{
    public function __construct(private readonly TlsProbe $probe) {}

    public function name(): string
    {
        return 'acme';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        return $this->run($domain, (string) config('sellchase.storefront.ssl.acme.issue_command', ''));
    }

    public function renew(StoreDomain $domain): CertificateResult
    {
        $command = (string) config('sellchase.storefront.ssl.acme.renew_command', '');

        // Most ACME clients treat renewal as a re-issue of the same certificate.
        return $this->run($domain, $command !== '' ? $command : (string) config('sellchase.storefront.ssl.acme.issue_command', ''));
    }

    public function revoke(StoreDomain $domain): CertificateResult
    {
        $command = (string) config('sellchase.storefront.ssl.acme.revoke_command', '');
        if ($command === '') {
            return CertificateResult::revoked();
        }

        $this->execute($this->render($command, $domain));

        return CertificateResult::revoked();
    }

    public function status(StoreDomain $domain): CertificateResult
    {
        $cert = $this->probe->inspect($domain->host);

        if ($cert === null) {
            return CertificateResult::pending('No certificate is being served for this host yet.');
        }

        // Same validation as the proxy provider: an ACME client can exit 0 while
        // the edge still serves a stale or wrong-host certificate.
        if (! $this->probe->certificateCovers($cert['san'], $domain->host)) {
            return CertificateResult::failed(
                'The certificate being served does not cover this host.',
            );
        }

        if ($cert['expires_at'] !== null && $cert['expires_at']->getTimestamp() <= time()) {
            return CertificateResult::failed('The certificate being served has expired.');
        }

        return CertificateResult::issued(
            $cert['issuer'],
            $cert['fingerprint'],
            $cert['san'],
            $cert['issued_at'],
            $cert['expires_at'],
        );
    }

    private function run(StoreDomain $domain, string $template): CertificateResult
    {
        if (trim($template) === '') {
            return CertificateResult::failed(
                'No ACME command is configured (sellchase.storefront.ssl.acme.issue_command).',
                retryable: false,
            );
        }

        $result = $this->execute($this->render($template, $domain));

        if (! $result['ok']) {
            return CertificateResult::failed(
                'ACME client failed: '.$this->summarise($result['output']),
                // Let's Encrypt rate limits are per registered domain and last
                // hours — treat an explicit rate-limit response as non-retryable
                // for this pass so backoff does not burn the remaining budget.
                retryable: ! str_contains(strtolower($result['output']), 'too many'),
            );
        }

        // Trust the edge, not the exit code.
        return $this->status($domain);
    }

    private function render(string $template, StoreDomain $domain): string
    {
        return str_replace(
            ['{host}', '{email}'],
            [
                escapeshellarg($domain->host),
                escapeshellarg((string) config('sellchase.storefront.ssl.acme.email', '')),
            ],
            $template,
        );
    }

    /** @return array{ok: bool, output: string} */
    private function execute(string $command): array
    {
        $timeout = (int) config('sellchase.storefront.ssl.acme.timeout', 180);

        try {
            $process = Process::timeout($timeout)->run($command);

            return [
                'ok' => $process->successful(),
                'output' => trim($process->errorOutput() ?: $process->output()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    private function summarise(string $output): string
    {
        $output = preg_replace('/\s+/', ' ', $output) ?? $output;

        return mb_substr(trim($output), 0, 300) ?: 'no output';
    }
}
