<?php

namespace App\Services\Stores\Ssl;

/**
 * Reads the certificate a host is actually serving.
 *
 * This is how every provider reports real state: rather than trusting what an
 * API claims was issued, we look at what the edge is presenting. Providers that
 * issue out-of-band (Caddy on-demand, a reverse proxy, cPanel AutoSSL) have no
 * other way to report status at all.
 *
 * A seam, so tests never touch the network.
 */
class TlsProbe
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
    ) {}

    /**
     * @return array{issuer: ?string, fingerprint: ?string, san: list<string>, issued_at: ?\DateTimeImmutable, expires_at: ?\DateTimeImmutable}|null
     *                                                                                                                                               Null when no TLS certificate could be read (connection refused, handshake failure, timeout).
     */
    public function inspect(string $host, int $port = 443): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // We are inspecting, not trusting: a self-signed or mismatched
                // certificate must still be readable so we can REPORT it as
                // broken rather than silently failing to connect.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            return null;
        }

        $parsed = @openssl_x509_parse($cert);
        if (! is_array($parsed)) {
            return null;
        }

        return [
            'issuer' => $this->issuerName($parsed),
            'fingerprint' => $this->fingerprint($cert),
            'san' => $this->subjectAltNames($parsed),
            'issued_at' => $this->timestamp($parsed['validFrom_time_t'] ?? null),
            'expires_at' => $this->timestamp($parsed['validTo_time_t'] ?? null),
        ];
    }

    /**
     * Does this certificate actually cover $host?
     *
     * The probe deliberately connects with verify_peer disabled so a broken
     * certificate can still be READ and reported. That means the bytes we get
     * back are untrusted: a host could be fronted by a self-signed certificate,
     * or by a valid certificate issued for an entirely different name. Without
     * this check a domain would be marked ssl_status = active on the strength of
     * a certificate that browsers will reject.
     *
     * Matches SAN entries exactly, plus single-label wildcards (`*.example.com`
     * covers `a.example.com` but NOT `example.com` or `a.b.example.com`), per
     * RFC 6125.
     *
     * @param  list<string>  $san
     */
    public function certificateCovers(array $san, string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || $san === []) {
            return false;
        }

        foreach ($san as $name) {
            $name = strtolower(rtrim(trim($name), '.'));

            if ($name === $host) {
                return true;
            }

            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                if (! str_ends_with($host, $suffix)) {
                    continue;
                }
                // The wildcard covers exactly one label.
                $label = substr($host, 0, -strlen($suffix));
                if ($label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function issuerName(array $parsed): ?string
    {
        $issuer = $parsed['issuer'] ?? null;
        if (! is_array($issuer)) {
            return null;
        }

        // Prefer CN, fall back to O — matches how issuers are commonly named.
        foreach (['CN', 'O'] as $key) {
            $value = $issuer[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return $value[0];
            }
        }

        return null;
    }

    private function fingerprint(mixed $cert): ?string
    {
        $fingerprint = @openssl_x509_fingerprint($cert, 'sha256');

        return is_string($fingerprint) ? strtolower($fingerprint) : null;
    }

    /** @return list<string> */
    private function subjectAltNames(array $parsed): array
    {
        $raw = $parsed['extensions']['subjectAltName'] ?? '';
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        return array_values(array_unique($names));
    }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_int($value) || $value <= 0) {
            return null;
        }

        return (new \DateTimeImmutable)->setTimestamp($value);
    }
}
