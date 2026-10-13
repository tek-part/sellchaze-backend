# Device order notifications

Only the store owner can configure their current authenticated device using
`GET/PUT /api/v1/my-store/order-push`. A session-bound access token is required;
legacy tokens without a session must sign in again. Device credentials are
encrypted at rest and never returned by the API. Optimistic versions reject
stale saves. An endpoint already owned by another account must be unsubscribed
in that browser before enabling a new subscription.

Provision a matching P-256 VAPID key pair privately on the server. Set
`MERCHANT_PUSH_VAPID_PUBLIC_KEY`, `MERCHANT_PUSH_VAPID_PRIVATE_KEY`, and
`MERCHANT_PUSH_VAPID_SUBJECT` (a valid `mailto:` contact or HTTPS URL).
Never commit keys. Blank or malformed configuration keeps enrollment and
delivery disabled. Configure these separately from Fawaterak wallet keys.

The merchant dashboard must use HTTPS and serve `/merchant-order-push-sw.js`
at the root. The worker does not intercept fetches or cache account pages.
Permission is requested only after an explicit Save action. The storefront
worker is not used for merchant notification enrollment.

New checkout transactions record durable deliveries without contacting a push
provider. Existing orders are not backfilled. The Laravel scheduler runs
`merchant-push:deliver --limit=50` every ten seconds with overlap protection;
the host must run `schedule:run` each minute or an equivalent managed scheduler.
Delivery rechecks ownership, active login session, subscription version and
key fingerprint. Revoked/expired sessions, disabled devices and ownership
changes suppress queued notifications. Providers receive generic order text,
not customer names, totals or details. Clicking opens the local orders route,
where normal account permissions and wallet debt restrictions still apply.

Delivery retries transient errors with bounded backoff, expires after ten
minutes and stops after eight attempts. HTTP 404/410 disables only the matching
subscription version. A repeated provider acceptance after a network timeout
is possible; the browser notification tag replaces the same notification
without renotifying. This is not a claim of exactly-once provider delivery.

Before production activation verify private backups and additive migration
stages, the scheduler, actual browser enrollment and a new test order on each
supported browser. Unit tests mock delivery transport and do not prove provider
acceptance. Login session listing/export is self-only; deleting another session
invalidates its access token and suppresses that session's queued notifications.
