<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Services\Stores\StoreDomainResolver;
use Illuminate\Http\Request;

class StoreHeadCode
{
    public function configuration(Store $store): array
    {
        $stored = $store->head_code_configuration ?? [];

        return ['version' => max(1, (int) ($stored['version'] ?? 1)), 'html' => (string) ($stored['html'] ?? '')];
    }

    /** Executable merchant code belongs only on the resolved public tenant host. */
    public function publication(Store $store, Request $request): ?array
    {
        $html = $this->configuration($store)['html'];
        if (trim($html) === '' || $store->status !== 'active') {
            return null;
        }
        $resolver = app(StoreDomainResolver::class);
        $host = $resolver->normalizeHost($request->getHost());
        $reserved = [$resolver->baseDomain(), 'localhost', '127.0.0.1'];
        foreach (['app.url', 'sellchase.frontend_url', 'sellchase.storefront.spa_origin'] as $key) {
            $reserved[] = $resolver->normalizeHost(parse_url((string) config($key), PHP_URL_HOST) ?: null);
        }
        if (! $host || in_array($host, $reserved, true)) {
            return null;
        }
        $suffix = '.'.$resolver->baseDomain();
        if (str_ends_with($host, $suffix) && $resolver->isReservedLabel(substr($host, 0, -strlen($suffix)))) {
            return null;
        }

        return ['html' => $html, 'host' => $host, 'fingerprint' => hash('sha256', $html)];
    }
}
