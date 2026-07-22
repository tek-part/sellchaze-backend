# Phase 3.5 — Architecture Decisions

Decision records produced during Phase 3.5 hardening. No implementation of themes,
custom domains, cart, or checkout — these are the ratified directions for Phase 4+.

---

## Task 1 — Storefront rendering architecture

**Decision: Option C — Hybrid, with a headless React storefront (SSR) as primary
and the Blade layer retained as a minimal fallback + machine endpoints.**

- **Contract:** the storefront **JSON API** (`/api/v1/storefront/*`) is the stable,
  tenant-safe interface. It already isolates by `store_id` and is presentation-agnostic.
- **Primary UI (Phase 4+):** a **React storefront that consumes the API**, server-rendered
  (SSR/SSG) for SEO. Themes become React component packs + JSON settings; a page
  builder becomes a block schema rendered by React.
- **Retained Blade:** the current server-rendered pages stay as a dependency-free
  SEO/no-JS fallback and remain the home for machine endpoints (`sitemap.xml`, `robots.txt`).

### Why (evaluated across the required dimensions)

| Dimension | Blade SSR (A) | React+API (B) | **Hybrid (C) — chosen** |
|---|---|---|---|
| Themes | PHP view packs, weak DX | Component packs + JSON settings | React themes over a stable API |
| Theme marketplace | Hard (PHP packages) | Natural (versioned component packs) | Enabled via the API contract |
| Page builder | Awkward in Blade | Block-schema → React render | Block schema drives React |
| SEO | Native | Needs SSR | SSR React **+** Blade fallback = safest |
| Performance | Good w/ cache | Great w/ SSR + CDN | Great; degrade to Blade if SSR down |
| Maintainability | 3rd rendering stack | One React ecosystem (dashboard already React) | Converges on React; Blade shrinks |
| Scalability | Server-bound | Headless + CDN + edge | Headless primary, resilient fallback |

**Rationale:** a theme marketplace and page builder are fundamentally data-driven and
favor a headless React storefront; the API contract is already built and tenant-safe.
Pure Blade (A) makes themes/marketplace painful; pure React (B) risks SEO/availability
without a fallback. Hybrid keeps the SEO-native Blade path while moving the rich UI to
React — lowest risk, best long-term fit. **No migration performed in Phase 3.5.**

---

## Task 5 — Storefront catalog source of truth

**Decision: `StoreProduct` / `StoreCategory` are the single source of truth for all
storefront commerce.** The legacy `Product` / `Category` remain exclusively the B2B
order/quotation catalog and are **not** used by storefront, cart, or checkout.

### Why
- **Tenancy:** `StoreProduct`/`StoreCategory` are store-scoped (`store_id`, `BelongsToStore`,
  fail-closed `StoreScope`). The B2B `Product`/`Category` are user-scoped and have no
  slugs, storefront price semantics, or store isolation.
- **Commerce shape:** storefront commerce needs per-store pricing, slugs, publication
  state, and (Phase 4) variants/inventory/SKU — all of which belong on `StoreProduct`,
  not the B2B `Product` (which is a quotation line concept, priced via `order_quotations`).
- **Separation:** keeping the two apart preserves the working B2B system untouched and
  avoids a dual-purpose table with ambiguous ownership.

### Consequences for Phase 4 (checkout)
- Storefront **orders/customers/payments** will reference `StoreProduct` and live in
  **new storefront tables**, not the B2B `orders`.
- `StoreProduct` will gain **variants, inventory, and SKU** before cart/checkout.
- No data sync between `Product` and `StoreProduct`; a merchant using both maintains two
  catalogs by design (B2B procurement vs. retail storefront are different businesses).
