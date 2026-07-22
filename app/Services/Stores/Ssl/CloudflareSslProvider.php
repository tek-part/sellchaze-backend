<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

/**
 * Cloudflare for SaaS — "Custom Hostnames".
 *
 * Cloudflare owns issuance and renewal; we create/delete the custom hostname and
 * read its validation state. Credentials come from config, never from code.
 *
 * @see https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/
 */
class CloudflareSslProvider implements SslProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly TlsProbe $probe,
    ) {}

    public function name(): string
    {
        return 'cloudflare';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        if (! $this->configured()) {
            return CertificateResult::failed('Cloudflare provider is not configured.', retryable: false);
        }

        $response = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->endpoint(), [
                'hostname' => $domain->host,
                'ssl' => [
                    'method' => (string) config('sellchase.storefront.ssl.cloudflare.validation_method', 'http'),
                    'type' => 'dv',
                    'settings' => ['min_tls_version' => '1.2'],
                ],
            ]);

        if ($response->failed()) {
            return CertificateResult::failed(
                'Cloudflare custom hostname creation failed: '.$this->errorFrom($response->json()),
                // 4xx other than 429 is a real rejection; do not hammer it.
                retryable: $response->status() === 429 || $response->serverError(),
            );
        }

        return $this->fromCloudflarePayload($response->json('result') ?? []);
    }

    public function renew(StoreDomain $domain): CertificateResult
    {
        // Cloudflare renews automatically; renewal is a status read.
        return $this->status($domain);
    }

    public function revoke(StoreDomain $domain): CertificateResult
    {
        if (! $this->configured()) {
            return CertificateResult::revoked();
        }

        $id = $this->findHostnameId($domain);
        if ($id === null) {
            return CertificateResult::revoked(); // already absent
        }

        $this->http->withToken($this->token())->acceptJson()->delete($this->endpoint().'/'.$id);

        return CertificateResult::revoked();
    }

    public function status(StoreDomain $domain): CertificateResult
    {
        if (! $this->configured()) {
            return CertificateResult::failed('Cloudflare provider is not configured.', retryable: false);
        }

        $response = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->get($this->endpoint(), ['hostname' => $domain->host]);

        if ($response->failed()) {
            return CertificateResult::failed(
                'Cloudflare status lookup failed: '.$this->errorFrom($response->json()),
            );
        }

        $result = $response->json('result.0');
        if (! is_array($result)) {
            return CertificateResult::pending('Custom hostname not present at Cloudflare yet.');
        }

        return $this->fromCloudflarePayload($result);
    }

    /** @param array<string, mixed> $payload */
    private function fromCloudflarePayload(array $payload): CertificateResult
    {
        $status = (string) data_get($payload, 'ssl.status', 'pending');

        if ($status !== 'active') {
            return CertificateResult::pending('Cloudflare SSL status: '.$status);
        }

        return CertificateResult::issued(
            (string) data_get($payload, 'ssl.certificate_authority', 'cloudflare') ?: 'cloudflare',
            data_get($payload, 'ssl.signature') === null ? null : (string) data_get($payload, 'ssl.signature'),
            array_values(array_filter([(string) data_get($payload, 'hostname', '')])),
            $this->date(data_get($payload, 'ssl.uploaded_on') ?? data_get($payload, 'created_at')),
            $this->date(data_get($payload, 'ssl.expires_on')),
        );
    }

    private function findHostnameId(StoreDomain $domain): ?string
    {
        $response = $this->http->withToken($this->token())->acceptJson()
            ->get($this->endpoint(), ['hostname' => $domain->host]);

        $id = $response->json('result.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function errorFrom(mixed $json): string
    {
        $message = data_get($json, 'errors.0.message');

        return is_string($message) && $message !== '' ? $message : 'unknown error';
    }

    private function configured(): bool
    {
        return $this->token() !== '' && $this->zoneId() !== '';
    }

    private function token(): string
    {
        return (string) config('sellchase.storefront.ssl.cloudflare.api_token', '');
    }

    private function zoneId(): string
    {
        return (string) config('sellchase.storefront.ssl.cloudflare.zone_id', '');
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('sellchase.storefront.ssl.cloudflare.base_uri', 'https://api.cloudflare.com/client/v4'), '/');

        return $base.'/zones/'.$this->zoneId().'/custom_hostnames';
    }
}
