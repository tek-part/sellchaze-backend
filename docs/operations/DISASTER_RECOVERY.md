# Disaster recovery runbook

## Targets

- Tier-1 database and object storage: RPO 15 minutes, RTO 60 minutes.
- Application/configuration: RPO 24 hours, RTO 30 minutes from an immutable release artifact.
- Backups are encrypted, stored outside the primary region/account, and retained for 35 days by default.

## Required backup set

1. Database snapshot plus point-in-time logs.
2. Uploaded media/object storage with versioning enabled.
3. environment secrets and DNS inventory in the approved secret manager (never inside the backup archive).
4. deployed commit SHA, migration list, and theme package versions.

## Restore drill

1. Declare a drill and create an isolated recovery environment; never restore over production.
2. Restore the latest full snapshot, then replay logs to the selected recovery timestamp.
3. Restore versioned objects and deploy the exact recorded release SHA.
4. run `php artisan migrate:status`, `php artisan config:cache`, and `php artisan route:cache`.
5. Verify `/api/health/live` and `/api/health/ready`, then run the onboarding, procurement, storefront checkout, and theme rollback smoke journeys.
6. Compare tenant/store/order counts and a sampled SHA-256 manifest of uploaded objects.
7. Record achieved RPO/RTO, evidence links, discrepancies, owner, and remediation deadline.

Production failover requires incident-commander approval, a read-only window during final replay, DNS/CDN cutover, queue resumption after the database is authoritative, and a written rollback point.

