<?php

namespace App\Services\Outbox;

use App\Events\DomainEventPublished;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxPublisher
{
    public const MAX_ATTEMPTS = 5;

    /** @return array{published: int, failed: int} */
    public function publishPending(int $limit = 100): array
    {
        $ids = OutboxMessage::query()
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        $result = ['published' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $published = DB::transaction(function () use ($id): ?bool {
                $message = OutboxMessage::query()->lockForUpdate()->find($id);

                if ($message === null || $message->published_at !== null || $message->failed_at !== null) {
                    return null;
                }

                try {
                    DomainEventPublished::dispatch($message);
                    $message->forceFill([
                        'published_at' => now(),
                        'last_error' => null,
                    ])->save();

                    return true;
                } catch (Throwable $exception) {
                    $attempts = $message->attempts + 1;
                    $message->forceFill([
                        'attempts' => $attempts,
                        'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                        'available_at' => now()->addSeconds(min(300, 2 ** $attempts)),
                        'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
                    ])->save();

                    return false;
                }
            });

            if ($published === true) {
                $result['published']++;
            } elseif ($published === false) {
                $result['failed']++;
            }
        }

        return $result;
    }
}
