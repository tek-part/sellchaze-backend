<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class PublishOutboxMessages extends Command
{
    protected $signature = 'outbox:publish {--limit=100 : Maximum messages to publish}';

    protected $description = 'Publish pending transactional outbox messages';

    public function handle(OutboxPublisher $publisher): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $result = $publisher->publishPending($limit);

        $this->info("Published {$result['published']} message(s); {$result['failed']} attempt(s) failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
