# Price adjustments by payment method

Each method accepts `presentation.price_adjustment` containing `enabled`,
`type` (`fixed` or `percentage`), `direction` (`add` or `subtract`) and a plain
decimal `value`. Defaults are disabled/fixed/add/0.00. Existing settings and
orders acquire no fee automatically. Partial name/logo saves retain the rule.
Saving a disabled rule never activates the payment method itself.

Values must be positive when enabled. Percentages are strictly between zero and
100; fixed amounts cannot exceed 9999999999.99. Decimal strings are limited to
64 characters and can retain sub-cent percentages. Scientific notation is not
accepted. Calculation uses decimal arithmetic. The adjustment is rounded half
up to the order currency's supported minor unit (at most the platform's two
decimal order precision). A discount is capped at the original payable total.

The base is the order total after product/coupon discounts, shipping and existing
tax. The adjustment is a separate post-tax line and does not alter the coupon
discount, tax calculation, delivery rate or free-shipping threshold. The payable
total is `base_total + payment_adjustment_total`. It is shown before submission
and included exactly once in the provider charge, receipt, merchant summary,
order webhook and CSV export. BNPL itemized requests include a positive fee line
or an additional order discount. Fawaterak receives the final payable total as
one aggregate invoice line when an adjustment exists; the detailed order remains
in Sellchaze. See the [Tamara checkout schema](https://docs.tamara.co/reference/createcheckoutsession)
and [Tabby checkout schema](https://docs.tabby.ai/api-reference/checkout/create-a-session).

`POST /checkout/quote` accepts the chosen `payment_method`, and returns the
adjustment, adjusted total and `payment_adjustment_token`. Send that token with
`POST /checkout`. It binds the store, method, currency, base total and complete
rule to the server's quote. Missing, forged or stale tokens cannot create an
adjusted order. A rule enabled after an unadjusted quote also requires a fresh
review. Client amounts, percentages and directions never determine a charge.
Changing methods or basket/shipping/coupon values fetches a new quote and
disables submission while it is unavailable.

The new nullable `store_orders.payment_adjustment` JSON preserves the rule,
currency, method, original base and signed amount. Old orders remain null.
Changing payment settings never reprices an existing order or payment retry.
Permitted offline financial edits use the order's frozen rule and retain their
separate manual adjustment. Explicit totals on merchant-created manual orders
remain authoritative and do not acquire an automatic payment adjustment.
Online invoice totals stay locked under the existing edit rules. Rollback of a
populated snapshot column is rejected to retain order history.

Automated checks use disposable databases and mocked provider responses. No
merchant keys are installed, no gateway is activated and no real transaction is
performed by this change.
