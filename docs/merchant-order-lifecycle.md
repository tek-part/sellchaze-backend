# Merchant order lifecycle

The merchant order list has 17 tabs: all orders, 11 fulfillment states, three payment states and two manual review classifications. Payment and risk filters retain their own fields; they do not overwrite fulfillment status. `shipped` and `cancelled` retain their existing persisted codes and API contracts.

Physical fulfillment supports these paths:

```
pending -> confirmed -> processing -> waiting_for_pickup -> shipped -> delivered
                                     \--------------------^
pending / confirmed / processing / waiting_for_pickup -> cancelled
shipped -> returning_from_delivery -> refunded
delivered -> request_refund -> refund_in_progress -> refunded
```

`refunded` is the compatibility code for the **Returned / تم الارجاع** fulfillment label. The four return states track physical product returns. They do not issue a financial refund, modify payment transactions, or restock goods. The merchant must reconcile received stock separately. The existing paid-only digital completion workflow remains unchanged; purely digital orders cannot enter physical pickup or return stages. Mixed orders retain their digital payment and delivery records during a physical return.

Reservations remain held while waiting for pickup, are released on cancellation, and commit on shipment. Carrier callbacks can advance a waiting-for-pickup order without rewinding a return workflow. New payment confirmation or payment retry is unavailable for a return workflow; authenticated provider settlement callbacks continue to record payment truth independently.

Single fulfillment changes use the existing status endpoint, audit timeline, notifications and order-status webhooks. The status and timeline columns accommodate 64 characters. Migration rollback refuses to discard any new lifecycle state in either current rows or history.

`POST /orders/bulk-status` accepts an optional `eligible_only: true`. In that mode, invalid lifecycle transitions are skipped and the response reports `updated`, `skipped` and `unchanged`. Omitted/false retains the legacy all-or-nothing eligibility contract. Foreign, wallet-locked and stale rows still reject the whole batch. Already-applied target states can be replayed without duplicating events.

The same bulk endpoint accepts `high_risk` and `moderate_risk`, requiring an expected `review_version` for each selected order. These are manual review classifications, using the same versioned review service as the order detail page. They preserve fulfillment, money and inventory. A conflicting review rolls back the entire batch. `classification` is an independent list/export filter; historical export encryption, ownership and wallet visibility rules apply.

Remaining parity work includes the competitor's payment options in the bulk selector, authoritative bot/IP-country presentation and any unverified return/refund automation. Payment settlement must continue through a verified gateway or the existing explicit bank/cash confirmation flows.
