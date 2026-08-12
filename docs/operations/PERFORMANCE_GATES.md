# Performance gates

## Reproducible local HTTP gate

Run the performance fixture through four independent PHP workers behind the
built-in round-robin proxy:

```bash
composer performance:cluster
```

The command seeds an idempotent representative fixture, starts and waits for
the workers, executes 40 requests per scenario, writes the machine-readable
report to `storage/app/performance/latest.json`, and always stops its workers.
It exits non-zero when an HTTP response fails or a percentile exceeds its
budget.

The enforced server budgets are:

- cached storefront TTFB p75 <= 800ms;
- storefront search, feed and notification reads p95 <= 300ms;
- representative notification write p95 <= 500ms.

The frontend `npm run test:perf` gate runs against the optimized `dist`
artifact and enforces LCP <= 2.5s, trusted-interaction INP <= 200ms, CLS <= 0.1
and navigation TTFB <= 800ms for every official theme.

## Production acceptance

Local synthetic results do not prove the public p75 or p95. Production
acceptance still requires seven dated days of RUM LCP/INP/CLS, public TTFB and
API latency from the target database, worker topology, CDN and geographic
traffic mix. Attach those dashboards to the release evidence described in
`PRODUCTION_ACCEPTANCE.md`.
