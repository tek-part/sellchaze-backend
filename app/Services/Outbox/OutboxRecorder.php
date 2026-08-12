<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

class OutboxRecorder
{
    /** @param array<string, mixed> $payload @param array<string, mixed> $metadata */
    public function record(
        string $eventType,
        string $aggregateType,
        string|int $aggregateId,
        array $payload,
        array $metadata = [],
    ): OutboxMessage {
        return OutboxMessage::query()->create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'payload' => $payload,
            'metadata' => $metadata,
            'available_at' => now(),
        ]);
    }
}
