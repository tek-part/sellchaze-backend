<?php

namespace App\Services\Commerce;

use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Resolve on every attempt and pin the validated address for the actual TLS request. */
class WebhookDestination
{
    public function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! $parts || ($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->publicAddress($host);
        }

        return str_contains($host, '.') && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $host) === 1
            && ! preg_match('/\.(?:localhost|local|internal|home|lan)$/iD', $host);
    }

    public function publicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            return IpUtils::checkIp($ip, '2000::/3') && ! IpUtils::checkIp($ip, ['2001::/32', '2001:db8::/32', '2002::/16']);
        }

        return ! IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
            '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }

    /** @return list<string> */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return array{host:string,ip:string,port:int} */
    public function resolve(string $url): array
    {
        if (! $this->validUrl($url)) {
            throw new RuntimeException('unsafe_destination');
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new RuntimeException('dns_unavailable');
        }
        foreach ($addresses as $ip) {
            if (! $this->publicAddress($ip)) {
                throw new RuntimeException('unsafe_destination');
            }
        }

        return ['host' => $host, 'ip' => $addresses[0], 'port' => (int) (parse_url($url, PHP_URL_PORT) ?: 443)];
    }
}
