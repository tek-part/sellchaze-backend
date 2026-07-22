<?php

namespace App\Services\Stores;

use App\Models\Store;

/**
 * Outcome of resolving a hostname to a store, including whether the matched
 * host is an alias (an old subdomain kept for 301 redirects) and the current
 * canonical host to redirect to.
 */
class ResolvedStore
{
    public function __construct(
        public readonly Store $store,
        public readonly string $matchedHost,
        public readonly string $canonicalHost,
        public readonly bool $isAlias,
    ) {}
}
