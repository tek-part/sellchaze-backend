# Production source reconciliation — 2026-10-16

The live backend reports Git HEAD `8e431062629ca3b6220824a655e4415352823cf0`, with additional working-tree changes. Most already exist in main; remaining production behavior is now preserved in source: optional country currency selection, enabled payment-method discovery, imported guest review schema and photos, and POST compatibility routes for A/B updates and removal.

The two added migrations are already recorded as applied in production. The private audit found 248 migration records and no pending migrations against the reconciled source. Eighteen historical migration records still lack source files, as documented in the earlier production baseline. Their history remains unchanged.

The current product-page preset validation is retained. Older working-tree landing-page code and older tests do not replace newer main implementations. Credentials, customer data, logs, runtime GeoIP databases, uploads and generated bundles remain outside this source change.

Regression coverage checks currency fallback and caching, private currency responses and store isolation, enabled payment methods, guest review visibility and photos, and version/ownership enforcement on the compatibility routes.
