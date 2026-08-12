# ADR-003: Contract-first API v2 with compatible v1 migration

- Status: Accepted
- Date: 2026-08-12

## Decision

`/api/v1` remains stable while capabilities move incrementally to `/api/v2`. The authoritative v2 contract is `openapi/v2.yaml`. Tenant resources use organization-scoped URLs, JSON errors follow Laravel's validation envelope, administration lists use page pagination, and feed/message streams will use cursors.

Changes to v2 require updating the OpenAPI document and contract tests in the same change. Destructive v1 changes are not allowed during the migration window.

