<?php

namespace App\Services\Stores;

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
    public function aaaa(string $name): array
    {
        return $this->records($name, DNS_AAAA, 'ipv6');
    }

    /**
     * @return list<string>
     */
    private function records(string $name, int $type, string $key): array
    {
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
}
