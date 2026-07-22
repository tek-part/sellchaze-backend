<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ActivityLogger
{
    /**
     * @param  array<string,mixed>  $properties
     * @param  array<string,mixed>  $context
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        string $eventType = 'user_action',
        string $channel = 'http',
        string $level = 'info',
        array $context = []
    ): ActivityLog {
        $request = request();
        $payload = [
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ];

        if (self::supportsExtendedColumns()) {
            $payload['event_type'] = $eventType;
            $payload['channel'] = $channel;
            $payload['level'] = $level;
            $payload['context'] = $context === [] ? null : $context;
        }

        return ActivityLog::query()->create($payload);
    }

    private static function supportsExtendedColumns(): bool
    {
        static $ready;
        if (is_bool($ready)) {
            return $ready;
        }

        $ready = Schema::hasColumns('activity_logs', ['event_type', 'channel', 'level', 'context']);

        return $ready;
    }
}
