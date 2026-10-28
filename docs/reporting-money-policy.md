# Pinned store reporting money

Historical `store_orders.currency`, order totals, and item snapshots stay in their original currency. The reporting helper is read-only. Catalogue conversion and payment currency are separate operational actions.

Persist the optional policy on `stores.theme_settings.reporting_money_policy` using a Store model save. Saves validate the policy; invalid pre-existing JSON fails closed with null equivalents and `policy_valid: false`. Theme publication preserves the existing store policy when replacing presentation settings. To remove a policy intentionally, set the key explicitly to null. Changing store currency while retaining an incompatible policy is rejected.

```json
{
  "version": "cbk-2026-10-27-v1",
  "reporting_currency": "KWD",
  "rates": {"USD": "0.308150", "KWD": "1"},
  "source_url": "https://www.cbk.gov.kw/en/monetary-policy/market-operations/exchange-rates",
  "effective_date": "2026-10-27"
}
```

This is an example schema, not an installed policy or an independent confirmation of a quote. Operations must persist the authorized rate/source/date/version for the intended store. No store ID or FX rate is hardcoded in application code. Rates must be positive decimal strings, keyed by uppercase three-letter currency, and the reporting currency rate must equal one. With no policy only store-currency records have an identity equivalent; foreign currency never defaults to one. Global live currency quotes are never read.

Every order grand total, merchandise amount, saved extended item cost, item line total, and coupon usage discount is converted and rounded HALF_UP to two decimals before summation. MySQL uses explicit DECIMAL operands; SQLite uses a BC Math UDF at the rounding boundary because SQLite DECIMAL/ROUND otherwise uses floating-point affinity. Two-decimal capacity matches the native order/catalogue schema. AOV divides the sum of converted order totals by the unchanged eligible order count and rounds HALF_UP to two decimals.

Order metrics conserve converted order records. Product/category/funnel metrics conserve converted item records. A converted order total need not equal converted lines: shipping, tax, discounts and per-record rounding can differ. Reports do not allocate residual cents across immutable historical lines. Saved costs are interpreted in the owning order currency; null costs or delivered orders with no lines make profit unknown. No catalogue cost is substituted for absent historical snapshots.

`reporting_money` exposes `reporting_currency`, `policy`, `policy_valid`, `rounding`, `totals_by_currency` (each original total, count and average), `count`, `base_equivalent`, `average_base_equivalent`, and `complete`. Product counts are line-record counts; order summaries/customer counts are order-record counts. Existing scalar API fields use the same equivalent expression for compatibility. One unknown rate makes the entire corresponding equivalent/AOV null while retaining every original bucket. Empty sets return zero. Normalized spend filters do not admit incomplete customers; normalized sorting places unknown equivalents last. Delivered-order and noncancelled-customer/funnel eligibility remains unchanged.

Dashboard and daily series, top products/categories, customer directory/profiles/ranking/filter/VIP selection, coupon analytics, product funnel and customer CSV share this policy. CSV keeps its first twelve field positions and appends reporting currency, original bucket JSON, completeness and policy version. Monetary headers identify equivalent values. Individual orders, receipts, print, order CSV and order history retain original currency formatting. Amount sorting compares reporting equivalents without changing displayed original amounts.

The overview cache retains normal order-change invalidation and also checks the current policy metadata before reuse. Policy changes therefore cannot return an earlier policy's cached totals.

Validation: synthetic in-memory fixtures use USD 100 plus KWD 10 => KWD 40.82, original buckets USD 100/KWD 10, AOV 20.41. Fixtures cover the same total across dashboard/daily/product/category/customer/funnel/filters/CSV, immutable snapshots, unknown currency, record rounding, native identity, policy persistence/cache, sorting/ranking, coupons and missing costs. No production PII or provider calls are needed.
