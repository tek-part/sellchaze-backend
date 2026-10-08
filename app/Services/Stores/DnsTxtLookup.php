<?php

namespace App\Services\Stores;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin seam over the system DNS resolver.
 *
 * Exists so domain verification can be driven deterministically in tests (bind a
 * fake in the container) without reaching the network, and so the lookup can be
 * swapped for a DoH/managed resolver later without touching StoreDomainService.
 *
 * DNS only — never an HTTP fetch against a user-supplied host, which would turn
 * verification into an SSRF primitive.
 */
class DnsTxtLookup
{
    /**
     * All TXT record strings published at $name.
     *
     * @return list<string>
     */
    public function txt(string $name): array
    {
        if (config('sellchase.storefront.domains.dns_resolver') === 'google') {
            return $this->publicRecords($name, 16);
        }
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false || $records === null) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            // PHP exposes chunked TXT strings in `entries` and the joined form in `txt`.
            if (isset($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    $values[] = (string) $entry;
                }
            }
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return array_values(array_unique(array_map('trim', $values)));
    }

    /**
     * CNAME targets published at $name (normalised, no trailing dot).
     *
     * @return list<string>
     */
    public function cname(string $name): array
    {
        return $this->records($name, DNS_CNAME, 'target');
    }

    /**
     * A records published at $name.
     *
     * @return list<string>
     */
    public function a(string $name): array
    {
        return $this->records($name, DNS_A, 'ip');
    }

    /**
     * AAAA records published at $name.
     *
     * @return list<string>
     */
    public function ns(string $name): array
    {
        return $this->records($name, DNS_NS, 'target');
    }

    public function aaaa(string $name): array
    {
        return $this->records($name, DNS_AAAA, 'ipv6');
    }

    /**
     * @return list<string>
     */
    private function records(string $name, int $type, string $key): array
    {
        if (config('sellchase.storefront.domains.dns_resolver') === 'google') {
            return $this->publicRecords($name, [DNS_A => 1, DNS_NS => 2, DNS_CNAME => 5, DNS_AAAA => 28][$type]);
        }
        $records = @dns_get_record($name, $type);

        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            $value = $record[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = strtolower(rtrim(trim($value), '.'));
            }
        }

        return array_values(array_unique($values));
    }

    /** Use public recursion so a locally prepared zone cannot fake global DNS propagation. */
    private function publicRecords(string $name, int $type): array
    {
        $body = Http::acceptJson()->connectTimeout(3)->timeout(8)
            ->get('https://dns.google/resolve', ['name' => $name, 'type' => $type])->throw()->json();
        $status = $body['Status'] ?? null;
        if ($status === 3) {
            return [];
        } // NXDOMAIN is a conclusive negative answer.
        if ($status !== 0) {
            throw new RuntimeException('Public DNS resolver could not complete the lookup.');
        }
        $values = [];
        foreach ($body['Answer'] ?? [] as $record) {
            if (($record['type'] ?? null) !== $type || ! is_string($record['data'] ?? null)) {
                continue;
            }
            $value = trim($record['data']);
            if ($type === 16) {
                $value = str_replace('" "', '', trim($value, '"'));
            } else {
                $value = strtolower(rtrim($value, '.'));
            }
            $values[] = $value;
        }

        return array_values(array_unique($values));
    }
}
