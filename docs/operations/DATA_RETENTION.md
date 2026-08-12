# Data retention and deletion policy

Defaults are centralized in `config/operations.php`; legal requirements and signed customer contracts override them.

| Data | Default | Treatment |
|---|---:|---|
| Published outbox messages | 30 days | prune only after publication and downstream reconciliation |
| Application logs | 30 days | redact secrets and direct identifiers at ingestion |
| Audit/security logs | 365 days | immutable access-controlled archive |
| Database backups | 35 days | encrypted and lifecycle-deleted |
| Active orders, invoices, and ledger records | statutory/contract term | restrict rather than erase where law requires retention |
| Closed account profile data | 30-day recovery window | anonymize or erase after identity and legal-hold checks |

Deletion jobs must be idempotent, tenant-scoped where applicable, emit an audit record, and support dry-run counts. A legal hold disables deletion for the scoped organization without changing unrelated tenants.

