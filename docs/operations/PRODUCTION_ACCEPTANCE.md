# Production acceptance gate

Local verification is necessary but cannot prove public production behavior. A release may be labelled accepted only after all rows below contain dated evidence from the target environment.

| Gate | Required evidence | Current state (2026-08-12) |
|---|---|---|
| Quality workflow | Green backend MySQL 8.4 and frontend Node 22 workflow for the exact release SHA | Pending: current changes are local and GitHub CLI is not authenticated |
| Deployment | Green deployment runs for the exact release SHA | Blocked externally: latest public backend and frontend deployment runs failed before these local changes |
| Database | `migrate --force` plus smoke queries on a restored production-like backup | Pending staging |
| Public health | `/api/health/live` and `/api/health/ready` return 200 through the public load balancer | Local 200 only |
| CDN | Cache MISS/HIT, purge after publication, Brotli and responsive-image transformer proof | Contract verified locally; provider evidence pending |
| Domains/SSL | Automated issuance, renewal and expiry alert on a real custom domain | Lifecycle tested with provider adapters; public certificate pending |
| Performance | RUM p75 LCP/INP/CLS and server p75/p95 for seven days | Local budgets pass; production window pending |
| Availability | 99.9% SLO measured for the agreed window | Pending monitoring window |
| Disaster recovery | Timed restore into an isolated environment with checksum and application smoke test | Runbook exists; drill pending |
| Security | Independent penetration test, dependency/SAST evidence and remediation sign-off | Local audits pass; external test pending |
| Beta | Named cohort, onboarding funnel, RFQ conversion and support outcomes | Product/business operation pending |

## Release procedure

1. Commit the reviewed backend and frontend worktrees and push a release branch.
2. Keep both quality workflows required before merging. Deployment is triggered only by a successful main-branch quality run and resets the server to that run's exact tested SHA.
3. Repair or configure the SSH deployment secrets and validate host keys before retrying deployment.
4. Run the migration and smoke checklist in staging, then execute the restore drill.
5. Deploy the exact accepted SHA, capture URLs/screenshots/metrics, and fill every row above.
6. Roll back if readiness, isolation, checkout, RFQ, theme publication or cache invalidation fails.

The `production` GitHub environment must define `SSH_HOST`, `SSH_USERNAME`,
`SSH_PRIVATE_KEY` and `SSH_HOST_FINGERPRINT`. The fingerprint is mandatory so a
compromised DNS record cannot redirect a release to an untrusted SSH host.

No local report should replace these external proofs or claim 100% production readiness without them.
