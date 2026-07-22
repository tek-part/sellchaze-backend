<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImpersonationApiController extends Controller
{
    /**
     * Admin-only: issue JWT tokens for another user (sign-in-as) for support / debugging.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if (! $actor->hasRole('Admin')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target = User::query()->with(['roles', 'permissions', 'profile'])->findOrFail((int) $data['user_id']);

        if ((int) $target->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'user_id' => [__('You cannot impersonate yourself.')],
            ]);
        }

        if ($target->is_active === false) {
            throw ValidationException::withMessages([
                'user_id' => [__('This account is deactivated.')],
            ]);
        }

        if ($target->pending_approval) {
            throw ValidationException::withMessages([
                'user_id' => [__('This account is not approved yet.')],
            ]);
        }

        if (! $this->isImpersonationTargetAllowed($target)) {
            throw ValidationException::withMessages([
                'user_id' => [__('This user cannot be opened with “sign in as”.')],
            ]);
        }

        $jwt = JwtTokenService::fromConfig();
        $sessionId = (string) Str::uuid();
        $meta = $this->requestMeta($request);

        $target->last_login_at = now();
        $target->last_login_ip = $request->ip();
        $target->save();

        LoginHistory::query()->create([
            'user_id' => $target->id,
            'login_method' => 'admin_impersonate',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        ActivityLogger::log(
            'admin.impersonation_started',
            $target,
            [
                'target_user_id' => $target->id,
                'target_email' => $target->email,
                'admin_user_id' => $actor->id,
                'admin_email' => $actor->email,
            ],
        );

        $target->load(['roles', 'permissions', 'profile']);

        return response()->json([
            'access_token' => $jwt->issueAccessToken($target, $sessionId),
            'refresh_token' => $jwt->issueRefreshToken($target, $sessionId, $meta),
            'token_type' => 'Bearer',
            'expires_in' => JwtTokenService::accessTokenExpiresInSeconds(),
            'user' => new UserResource($target),
        ]);
    }

    private function isImpersonationTargetAllowed(User $target): bool
    {
        $email = strtolower((string) $target->email);
        if (str_starts_with($email, 'guest_') && str_ends_with($email, '@wigpleasure.com')) {
            return false;
        }

        return $target->hasAnyRole(['Staff', 'Manager', 'Admin', 'Merchant', 'Supplier']);
    }

    /**
     * @return array{ip_address: ?string, user_agent: ?string, device_name: ?string}
     */
    private function requestMeta(Request $request): array
    {
        $userAgent = $request->userAgent();

        return [
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'device_name' => null,
        ];
    }
}
