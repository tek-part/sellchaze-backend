# Merchant payment methods

`PaymentGatewayCatalog` defines the 20 reference methods plus the existing Bank
Transfer, Fawry and HyperPay connections. Provider identity, required credentials
and default logo paths are shared by the settings and public methods APIs.
Taager and FABMISR remain unavailable until their merchant integration contracts
are supplied. Saving their draft settings does not make them selectable at checkout.

The eight new hosted integrations use the following primary contracts:

| Provider | Creation and verification | Configuration |
| --- | --- | --- |
| [Kashier](https://developers.kashier.io/docs/accept-payments/payment-sessions) | Create session; retrieve its payment | Merchant ID, payment API key, secret key |
| [Moyasar](https://docs.moyasar.com/category/invoices-api) | Create and retrieve hosted invoice | Secret key matching the selected test/live mode |
| [Paylink](https://developer.paylink.sa/docs/add-invoice) | Authenticate, add invoice, retrieve invoice | API ID and secret; test/live hosts differ |
| [Ziina](https://docs.ziina.com/api-reference/payment-intent) | Create and retrieve payment intent | API key; persistent operation UUID; explicit test flag |
| [MyFatoorah](https://docs.myfatoorah.com/docs/send-payment) | SendPayment with LNK; GetPaymentStatus by stored InvoiceId | Country and API token; order currency must equal the account base currency; sandbox uses KWD |
| [EasyKash](https://easykash.gitbook.io/easykash-apis-documentation/direct-payment-hosted/payment-inquiry) | Create direct payment link; inquire with original customer reference | API key, optional comma-separated payment option IDs. This connection supports EGP only; the documented API has no sandbox |
| [UPayments](https://developers.upayments.com/reference/checkpaymentstatus) | Charge; query status with the stored session ID | API key and HMAC secret; signed raw requests; non-whitelabel hosted checkout |
| [XPay Egypt](https://xpayeg.github.io/docs/pay/) | Variable-amount payment; retrieve transaction UUID | Legacy community API key, community ID and API Payment template ID. Configure the supplied URL as both Redirect URL and Callback URL in XPay. Use accounts with processing fees included in the charged amount |

No card number or CVV is collected or stored by these integrations. Returning
from a provider never proves payment. Notifications, return handlers and the
scheduled `payments:reconcile-hosted` command retrieve status using the stored
session and merchant account, then verify identity, amount, currency and the
original connection fingerprint before settlement. Paid transactions cannot be
reversed by delayed failure notifications. The order must still match its payment.

Unknown invoice-creation outcomes are not automatically reissued as new invoices;
they require reconciliation. Ziina retries preserve the same `operation_id`.
Provider credentials stay encrypted, secrets are masked, blank updates preserve
stored values, and a partial settings update leaves other methods untouched.

Transfer with receipt stores up to three 5 MB image/PDF proofs per private order
receipt. Files are kept on the private local disk and downloaded by an authorized
merchant through the store-scoped API. An upload stays pending. Merchant review
and confirmation of the bank reference is required before marking payment paid.
Rejection reasons are customer-facing; internal confirmation notes are not.

Presentation is additive nullable JSON on existing gateway rows. Reads create no
gateway rows, migrations enable no gateways, and deployment changes no merchant
keys, active settings or pricing. Logos can be customized using an owned image
asset. Existing transactions retain their presentation snapshot.

Optional per-method surcharges/discounts from the reference UI are not implemented
in this phase. No new charge, discount, currency conversion or commission is
applied by these settings.

Verification uses `Http::fake` and disposable SQLite test databases. Actual merchant
credentials, remote sandbox transactions and live payments are not exercised by
the automated tests.
