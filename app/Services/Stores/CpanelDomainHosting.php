<?php

namespace App\Services\Stores;

use App\Models\StoreDomain;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Runs as the existing cPanel account, never as root and never with an API token. */
class CpanelDomainHosting
{
    public function enabled(): bool
    {
        return (bool) config('sellchase.storefront.domains.cpanel.enabled', false);
    }

    public function call(string $module, string $function, array $args = [], bool $legacy = false): array
    {
        $binary = $legacy ? '/usr/local/cpanel/bin/cpapi2' : '/usr/local/cpanel/bin/uapi';
        $command = [$binary, '--output=json', $module, $function];
        foreach ($args as $key => $value) {
            $command[] = $key.'='.(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value);
        }
        $result = Process::timeout(90)->run($command);
        $body = json_decode($result->output(), true);
        $payload = $legacy ? ($body['cpanelresult'] ?? []) : ($body['result'] ?? []);
        $ok = $legacy ? ($payload['data'][0]['result'] ?? $payload['event']['result'] ?? 0) : ($payload['status'] ?? 0);
        if (! $result->successful() || (int) $ok !== 1) {
            Log::error('Domain hosting provider rejected a request', ['module' => $module, 'function' => $function, 'exit_code' => $result->exitCode(), 'errors' => $payload['errors'] ?? $payload['data'][0]['reason'] ?? null]);
            throw new RuntimeException('Hosting could not complete '.$module.'::'.$function.'. Please contact support to check the domain on the hosting account.');
        }

        return $payload['data'] ?? [];
    }

    public function prepare(StoreDomain $domain): void
    {
        if (! $this->enabled() || ! $domain->isCustom() || ! $domain->isServable()) {
            throw new RuntimeException('Verify domain ownership before preparing DNS hosting.');
        }
        app(StoreDomainService::class)->assertValidHost($domain->host);
        if ($domain->hosting_status === 'ready') {
            return;
        }
        if ($domain->hosting_status !== 'dns_pending' && ! app(StoreDomainService::class)->checkDns($domain)) {
            throw new RuntimeException('The ownership TXT record must still match before DNS hosting can be prepared.');
        }
        $nameservers = config('sellchase.storefront.domains.nameservers', []);
        if (count($nameservers) < 2) {
            throw new RuntimeException('Platform nameservers are not configured.');
        }

        if ($domain->hosting_status !== 'dns_pending') {
            $known = $this->call('DomainInfo', 'list_domains');
            $hosts = array_merge([$known['main_domain'] ?? ''], $known['addon_domains'] ?? [], $known['parked_domains'] ?? [], $known['sub_domains'] ?? []);
            if (in_array($domain->host, $hosts, true)) {
                throw new RuntimeException('This host already exists on the hosting account. Support must review its document root before continuing.');
            }
            $this->call('AddonDomain', 'addaddondomain', [
                'newdomain' => $domain->host,
                // Nameserver migration precedes A routing. The fresh TXT proof above
                // replaces the provider's destination-IP precondition for this host only.
                'force' => 1,
                'subdomain' => 'sc-domain-'.$domain->id,
                'dir' => config('sellchase.storefront.domains.cpanel.document_root'),
            ], legacy: true);
            // This marker makes a retry safe without adopting somebody else's host.
            $domain->forceFill(['hosting_status' => 'dns_pending', 'hosting_error' => null])->save();
        }

        $zone = $this->call('DNS', 'parse_zone', ['zone' => $domain->host]);
        $serial = null;
        $changes = ['zone' => $domain->host];
        $actualNameservers = [];
        foreach ($zone as $record) {
            if (($record['record_type'] ?? '') === 'SOA') {
                $serial = base64_decode($record['data_b64'][2] ?? '', true);
            }
            if (($record['record_type'] ?? '') === 'NS' && in_array($record['dname_raw'] ?? '', [$domain->host.'.', '@'], true)) {
                $actualNameservers[] = strtolower(rtrim((string) base64_decode($record['data_b64'][0] ?? '', true), '.'));
            }
        }
        if (! $serial) {
            throw new RuntimeException('Could not read the DNS zone serial.');
        }
        // cPanel deliberately reserves NS edits for the hosting operator. Account-specific
        // zone templates must provide these values; never widen the account's privileges.
        $expected = array_map(fn ($server) => strtolower(rtrim($server, '.')), $nameservers);
        sort($expected);
        sort($actualNameservers);
        if ($expected !== $actualNameservers) {
            throw new RuntimeException('The hosting account DNS template needs to be configured with the platform nameservers.');
        }
        $changes['serial'] = $serial;
        $changes['add-1'] = ['dname' => app(StoreDomainService::class)->verificationRecordName($domain).'.', 'ttl' => 300, 'record_type' => 'TXT', 'data' => [$domain->verificationTxtValue()]];
        $this->call('DNS', 'mass_edit_zone', $changes);
        $domain->forceFill(['hosting_status' => 'ready', 'hosting_error' => null])->save();
    }
}
