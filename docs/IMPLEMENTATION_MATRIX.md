# Sellchaze implementation and acceptance matrix

This is the authoritative delivery ledger for the 24-month product plan. “Implemented” is used only when code exists; “Verified” requires the listed evidence to pass in the current worktree or delivery environment.

## Phase 0 — foundation

| Requirement | State | Acceptance evidence |
|---|---|---|
| Official Sellchaze identity | Verified | Visible UI, email, feed, SVG and demo-store surfaces use Sellchaze; legacy lowercase storage/config/package identifiers remain stable compatibility contracts |
| Architecture decisions and boundaries | Verified for new core | ADR-001 through ADR-003 plus executable model/organization/procurement/subscription dependency rules |
| Production-compatible migrations | Verified on SQLite | Fresh 158-migration run passes from an empty database; MySQL CI added and must pass remotely |
| OpenAPI v2 | Verified for organization slice | Bidirectional route contract: 2 tests / 133 assertions, covering organization connections and all documented v2 operations; full legacy-resource migration remains incremental |
| CI quality gates | Implemented | Backend/frontend `quality.yml`; deployments run only after a successful main-branch quality workflow and deploy its exact tested SHA. Separate weekday scheduled performance and dependency-security gates detect regressions without a code push; remote green run pending |
| Design system, RTL/LTR, WCAG 2.2 AA | Verified automated storefront gate | Axe WCAG A/AA/2.2 blocks serious/critical findings across four themes in Arabic and English (8 browser tests) |
| Logs, traces, metrics, feature flags | Implemented foundation | Correlation IDs, structured context, tenant feature flags, liveness/readiness and Prometheus domain metrics; distributed trace exporter remains deployment work |
| Transactional outbox | Verified | scheduled publisher, retry/dead-letter state and once-only processing tests |
| Tenant security | Verified for organization slice | `OrganizationTenancyTest`: isolation, memberships, multi-store and outbox |

## Phase 1 — companies and subscriptions

| Requirement | State | Acceptance evidence |
|---|---|---|
| Organizations and memberships | Verified (API + first UI) | v2 CRUD, membership roles, tenancy tests and `/company` workspace |
| Multi-store company ownership | Verified (creation foundation) | quota-aware multi-store journey + isolation tests |
| Store-level permissions | Verified for organization/store APIs | membership `store_ids`, policies, `ScopeToStore`, foreign-store rejection and noisy-tenant isolation tests |
| Plan/Price/Entitlement/Quota separation | Verified | normalized catalog, four plans, quota engine and API tests |
| Company subscription and add-ons | Implemented (manual activation seam) | company-owned subscriptions, add-on schema, outbox event; billing provider pending |
| Company/team onboarding | Verified foundation | Atomic self-service registration provisions owner membership, primary draft store, domain and active theme; rollback and guided publish journey tested; teammate invitation remains an explicit owner action |

## Phases 2–6

The existing code contains partial implementations of feed, messaging, RFQ/quotations, stores, domains, orders, themes and page building. They are **not accepted as complete** until each journey has organization/store isolation, v2 contracts, E2E coverage, accessibility coverage and the performance budgets below.

| Phase | Exit journey | State |
|---|---|---|
| 2 — network, social, procurement | discovery → company connection → conversation/RFQ → accepted quotation → order | Verified locally: company identity/profile UI (locations, capabilities, certificates and featured products), personal/company posts with image/video/document links, follow/save/block/mute/report, moderation audit, cursor feed, audited company-to-company connection requests and acceptance, supplier/list/sector RFQs, items/attachments, immutable quote revisions, comparison, audit trail, conversation, atomic award and party-scoped order lifecycle |
| 3 — store platform | publish independent catalog store on domain → receive no-payment order | Verified locally: independent catalog, variants/inventory/coupons/reviews, no-payment checkout, configurable locale/currency/timezone/tax/shipping, multi-store permissions, domains/SSL lifecycle, SEO, SSR fallback and edge-cache contract. Real CDN/SSL issuance remains deployment evidence |
| 4 — Theme Studio | visually edit → autosave/revise → preview → publish → rollback | Verified locally: schema property rail, DnD/copy/hide/delete/reorder, bounded undo/redo, autosave, typed iframe bridge, responsive/language/path canvas, revisions, immutable publications, safe scoped CSS, server-backed marketplace, official-theme hot-reload CLI and package lifecycle, four distinct theme families and 8 deterministic RTL/LTR visual snapshots |
| 5 — performance/global | p75/p95 budgets under representative load, Arabic/English and global settings | Verified on the reproducible local HTTP cluster: 40 requests/scenario through four workers produced p75 TTFB 220.72ms, read p95 238.98–253.77ms at concurrency 4, and representative same-account write p95 363.39ms at concurrency 1. Browser gates now assert LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1 and TTFB ≤ 800ms on all four production theme bundles. Noisy-tenant isolation, bundle budgets and configurable locale/currency/timezone/tax/shipping gates pass. Deployed CDN/production-database evidence remains pending |
| 6 — launch maturity | DR restore, security review, retention, incident playbooks and beta evidence | Operational foundation implemented: dependency readiness probes, security-header tests, DR/incident/retention/security runbooks; restore drill and beta evidence require a deployed environment |

## Non-negotiable performance gates

- Storefront p75: LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1.
- Cached public TTFB p75 ≤ 800ms.
- API p95: reads ≤ 300ms and writes ≤ 500ms, excluding long jobs.
- Initial storefront JavaScript ≤ 150 KB gzip; each dashboard route ≤ 200 KB gzip.
- Initial availability target 99.9%.

## Evidence that requires a deployed environment

The following are intentionally not labelled "Verified" from a local workstation: remote MySQL CI, production-worker/database p75/p95, CDN cache HIT/invalidations, automated public SSL issuance, 99.9% availability, restore drill, penetration test and beta cohort outcomes. Their runbooks/contracts exist, but the evidence must be captured from staging/production rather than fabricated locally.

## Latest local acceptance run

- Laravel: 410 tests / 1,997 assertions; PHPStan and Composer audit are clean.
- Database: all 158 migrations complete from an empty SQLite database; MySQL 8.4 remains an enforced remote CI gate.
- Frontend: 31 Vitest files / 298 tests, TypeScript, full ESLint with zero warnings, production build and bundle budgets pass. Playwright measures the optimized production artifact: 8 accessibility, 4 performance, 8 RTL/LTR visual-contract and 1 PWA tests pass.
- Browser: 8 accessibility, 4 performance, 8 deterministic visual RTL/LTR, and 1 PWA test pass.
- The generated TypeScript API contract is current and npm audit reports zero vulnerabilities.
- A critical E2E journey passes registration, theme autosave and safe CSS, store publication, public checkout, merchant-to-supplier connection request and acceptance, targeted RFQ, supplier quotation and procurement-order creation in one test.
- Catalog ownership now matches the product decision: B2B rows stay store-less while each storefront is keyed by `store_id`; same-owner multi-store isolation has an executable regression test.
- Phase 5 includes signed provider-neutral responsive image URLs/srcsets, responsive theme settings, inline critical SSR CSS and actual storefront currency conversion.
- Phase 6 includes owner-scoped audit CSV export and scheduled retention with a dry-run mode, both covered by integration tests.
