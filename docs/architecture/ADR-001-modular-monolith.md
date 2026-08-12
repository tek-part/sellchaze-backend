# ADR-001: Modular monolith with explicit bounded contexts

- Status: Accepted
- Date: 2026-08-12

## Decision

Sellchaze remains a Laravel modular monolith. New behavior is grouped by business context, with thin HTTP controllers, application actions, policies, domain services, and events. The initial contexts are Identity & Organizations, Subscriptions & Entitlements, Business Network, Procurement & Quotations, Community & Messaging, Store Commerce, Catalog & Inventory, Themes & Page Builder, Notifications, and Administration & Trust.

Context boundaries are enforced by tests and documented contracts. A generic repository layer is prohibited; Eloquent is used directly unless a context needs an external data source or a complex read model.

## Consequences

- Transactions stay local and deployment remains simple while the product is evolving.
- Domain events are written through the transactional outbox so asynchronous consumers can be extracted later without changing the write contract.
- New v2 endpoints must not place business rules in controllers.

