<?php

return [
    'outbox_ready_backlog' => (int) env('OUTBOX_READY_BACKLOG', 5000),
    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    'retention' => [
        'published_outbox_days' => (int) env('RETENTION_PUBLISHED_OUTBOX_DAYS', 30),
        'application_logs_days' => (int) env('RETENTION_APPLICATION_LOGS_DAYS', 30),
        'audit_logs_days' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 365),
        'database_backups_days' => (int) env('RETENTION_DATABASE_BACKUPS_DAYS', 35),
    ],
];
