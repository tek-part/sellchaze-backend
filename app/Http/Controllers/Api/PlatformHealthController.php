<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformHealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'sellchaze-api',
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReady(),
            'cache' => $this->cacheIsReady(),
            'outbox' => $this->outboxIsReady(),
        ];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsReady(): bool
    {
        try {
            $key = 'health:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            $ready = Cache::pull($key) === 'ok';

            return $ready;
        } catch (Throwable) {
            return false;
        }
    }

    private function outboxIsReady(): bool
    {
        try {
            $backlog = OutboxMessage::query()
                ->whereNull('published_at')
                ->whereNull('failed_at')
                ->count();

            return $backlog <= (int) config('operations.outbox_ready_backlog', 5000);
        } catch (Throwable) {
            return false;
        }
    }
}

