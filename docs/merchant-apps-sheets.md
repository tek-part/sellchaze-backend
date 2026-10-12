# Merchant applications and Google Sheets

The tenant-scoped application directory lists 32 applications in nine categories. Eleven entries link to implemented native modules. Other entries are informational and explicitly unavailable; listing them does not install an integration, create a subscription, or authorize charges.

Google Sheets requires both store settings and order management permissions. An owner uploads a Google service-account JSON file and shares the chosen spreadsheet with that service-account email as an editor. Only RSA service-account keys of at least 2048 bits are accepted. Credentials and frozen delivery payloads are encrypted; responses never expose private keys, access tokens, or order payloads. Credential-supplied token URLs and domain delegation are ignored.

Connections have owned creation keys and versioned updates. A spreadsheet or service-account identity cannot be changed in place. Enabling or resuming a connection establishes a new-order cutoff; previous orders are never backfilled. Checkout records a durable delivery inside the order transaction without contacting Google. Order fee debt rules are enforced before delivery.

The existing Laravel scheduler runs `store-sheets:dispatch --limit=50` every minute. Database leases prevent overlapping deliveries. A dedicated, tagged worksheet reserves one row per order. Retries read that same row before writing, preserve conflicting user data, and stop after eight attempts. Failed deliveries may be explicitly requeued; completed deliveries cannot. Disconnecting archives a connection and preserves history and already exported spreadsheet rows.

Deployment must apply `2026_10_12_130000_create_store_sheet_connections.php` before switching application code, then verify the existing scheduler is running. Reversing the migration refuses populated connection or delivery history.

Provider behavior follows the [Google service-account protocol](https://developers.google.com/identity/protocols/oauth2/service-account), [Sheets values update API](https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets.values/update), and [RAW value input option](https://developers.google.com/workspace/sheets/api/reference/rest/v4/ValueInputOption). Tests use synthetic keys and mocked Google responses, including ambiguous writes and revocation during delivery. Live provider acceptance still requires a real shared spreadsheet and service account.
