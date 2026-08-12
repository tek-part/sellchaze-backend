<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FeedCache
{
    private const GENERATION_KEY = 'feed:generation';

    public function key(Request $request, int $viewerId, string $locale): string
    {
        $query = $request->query();
        ksort($query);

        return 'feed:payload:'.sha1(implode('|', [
            (string) $this->generation(), (string) $viewerId, $locale,
            json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    public function put(string $key, array $payload): void
    {
        Cache::put($key, $payload, now()->addSeconds(30));
    }

    public function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, $this->generation() + 1);
    }

    private function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 1);
    }
}
