# Incident response playbook

## Severity and ownership

- SEV-1: security breach, cross-tenant exposure, or platform-wide order/store outage. Page immediately; updates every 30 minutes.
- SEV-2: major journey degraded or a region/provider unavailable. Page the service owner; updates hourly.
- SEV-3: limited defect with a workaround. Track in the normal engineering queue.

The incident commander owns coordination, the operations lead owns mitigation, the communications lead owns customer updates, and the scribe preserves a timestamped log. No one performs an irreversible data repair without a peer-reviewed query and backup checkpoint.

## First 15 minutes

1. Confirm impact using health probes, correlation IDs, logs, outbox backlog, domain metrics, and provider dashboards.
2. Assign severity and roles; open a dedicated incident channel and timeline.
3. Stop the bleed with a feature flag, traffic isolation, queue pause, or rollback to a known artifact.
4. For suspected tenant leakage, revoke affected credentials, preserve logs, and involve the security owner before cleanup.

## Closure

Recovery requires the critical user journey and readiness probe to pass, backlog to drain, and monitoring to remain stable for 30 minutes. Publish a blameless review within five business days with root cause, detection gap, actions, owners, and dates.

