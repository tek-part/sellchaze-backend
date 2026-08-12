# Security release review

Before production release, capture evidence for:

- tenant and store authorization tests, IDOR attempts, targeted-RFQ confidentiality, and host-header poisoning;
- dependency audits, secret scanning, SAST/PHPStan, CSP reports, rate limits, and security headers;
- authentication reset/refresh/revocation flows and privileged-role changes;
- file upload type/size/storage isolation, webhook signature and replay controls, and outbound URL/SSRF controls;
- encrypted transport, managed secrets, least-privilege database/queue/storage identities, backup encryption, and restore drill;
- Arabic/English accessibility, Web Vitals budgets, load-test percentiles, alert thresholds, incident contacts, and rollback.

Any exception needs an owner, risk statement, compensating control, expiry date, and approver. A checklist without linked evidence is not a completed review.

