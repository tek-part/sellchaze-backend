# Merchant document head code

`GET/PUT /api/v1/my-store/head-code` and the scoped `stores/{store}/head-code`
routes expose a private, versioned `{version, html}` configuration. Only the
actual owner or a user with global `stores-edit` access can manage executable
code. `store.settings.manage` alone is insufficient. The existing store view
policy still protects the explicit-ID route. Writes preserve whitespace and
other settings; stale versions return 409. Blank code removes publication.

The nullable `stores.head_code_configuration` starts empty for existing stores.
Populated migration rollback is refused. Raw configuration is hidden from
general store model serialization.

Published code runs on a resolved, active tenant host, including that tenant's
isolated theme preview. The app origin, dashboard frontend origin, asset origin,
base domain, reserved subdomains and loopback hosts never publish it through
the backend. `APP_URL`, `FRONTEND_URL` and the storefront asset origin must
identify the real deployment origins. Do not host an authenticated dashboard
on a tenant origin. Existing DNS ownership checks remain in force.

The public web middleware inserts the code before `</head>` on successful HTML
responses, including the SPA shell and Blade fallback. HTML verification tags
therefore exist in the initial response. An exact content fingerprint prevents
the storefront bootstrap from executing server-inserted code twice. Standalone
SPA development uses a lazy renderer that recreates script nodes and preserves
ordered external-script dependencies. Its loopback exception requires an
explicit development-only storefront build; dashboard previews have no such
exception. The merchant editor only displays escaped, highlighted source.

The editor uses the reference UIW component with HTML, CSS and JavaScript
grammars loaded separately. Theme-preview setting decoding also loads only when
needed; the public storefront's existing 150 KiB initial bundle cap is retained.
DOM execution tests use only locally authored fixtures and disable external
resource loading. No competitor configuration is changed during review.
