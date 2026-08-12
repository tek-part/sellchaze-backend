# ADR-002: Organization is the tenant and subscription owner

- Status: Accepted
- Date: 2026-08-12

## Decision

The company (`organizations`) is the tenant boundary. A person can belong to several companies through `organization_memberships`; roles and optional store scopes live on that membership. Stores and subscriptions carry `organization_id`. Every v2 tenant resource is nested beneath `/organizations/{organization}` and authorized from the active membership, never from an organization identifier supplied in a request body.

The v1 user-owned model remains available only as a compatibility layer during migration. Its singular `/my-store` contract resolves the primary store. New business behavior supports multiple stores per company.

## Invariants

1. A user cannot read or mutate a company without an active membership, except a platform Admin.
2. Only owners and organization admins manage members or create stores.
3. Store-scoped member identifiers must reference stores in the same organization.
4. Exactly one primary store is selected when the first store is created; additional stores are non-primary.
5. Company creation and its `OrganizationCreated` outbox event commit atomically.

