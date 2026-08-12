<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            return new JsonResponse([
                'message' => 'The Idempotency-Key must contain 8 to 128 URL-safe characters.',
            ], 422);
        }

        $user = $request->user();
        if (! $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $scope = strtoupper($request->method()).' '.$request->path();
        $hash = hash('sha256', (string) json_encode(
            $this->canonicalize($request->all()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        $record = $this->claim((int) $user->getAuthIdentifier(), $scope, $key, $hash);
        if ($record->request_hash !== $hash) {
            return new JsonResponse([
                'message' => 'This Idempotency-Key was already used with a different request payload.',
            ], 409);
        }

        if ($record->state === 'completed') {
            return response($record->response_body ?? '', $record->response_status ?? 200, [
                'Content-Type' => $record->content_type ?? 'application/json',
                'Idempotency-Replayed' => 'true',
            ]);
        }

        if (! $record->wasRecentlyCreated) {
            return new JsonResponse([
                'message' => 'A request with this Idempotency-Key is still processing.',
            ], 409, ['Retry-After' => '1']);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();
            throw $exception;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $record->forceFill([
                'state' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent() ?: '',
                'content_type' => $response->headers->get('Content-Type', 'application/json'),
            ])->save();
            $response->headers->set('Idempotency-Replayed', 'false');
        } else {
            $record->delete();
        }

        return $response;
    }

    private function claim(int $userId, string $scope, string $key, string $hash): IdempotencyRecord
    {
        IdempotencyRecord::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('expires_at', '<=', now())
            ->delete();

        try {
            return IdempotencyRecord::query()->create([
                'user_id' => $userId,
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $hash,
                'state' => 'processing',
                'expires_at' => now()->addHours((int) config('operations.idempotency_ttl_hours', 24)),
            ]);
        } catch (UniqueConstraintViolationException) {
            return IdempotencyRecord::query()
                ->where('user_id', $userId)
                ->where('scope', $scope)
                ->where('key', $key)
                ->firstOrFail();
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
