<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\Profile;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Unauthenticated endpoints backing /u/{username} public profile pages.
 */
class PublicProfileApiController extends Controller
{
    public function show(Request $request, string $username): JsonResponse
    {
        $profile = Profile::query()->where('username', $username)->first();
        if (! $profile || ! $profile->is_public) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $user = User::query()->find($profile->user_id);
        if (! $user || ! ($user->is_active ?? true) || ! empty($user->pending_approval)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $role = $user->communityRole();

        $yearsActive = (int) max(0, $user->created_at ? now()->diffInYears($user->created_at) : 0);
        $followersCount = (int) Follow::query()->where('followed_id', $user->id)->count();
        $followingCount = (int) Follow::query()->where('follower_id', $user->id)->count();

        // Optional auth: this endpoint stays public, but a logged-in viewer's
        // token (the SPA always sends it) unlocks the relationship flags.
        $viewer = $this->resolveViewer($request);
        $viewerBlock = [
            'is_self' => $viewer !== null && (int) $viewer->id === (int) $user->id,
            'is_following' => $viewer !== null && Follow::query()->where('follower_id', $viewer->id)->where('followed_id', $user->id)->exists(),
            'follows_you' => $viewer !== null && Follow::query()->where('follower_id', $user->id)->where('followed_id', $viewer->id)->exists(),
        ];

        return response()->json([
            'viewer' => $viewerBlock,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $role,
                'is_verified' => (bool) ($user->is_verified ?? false),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => [
                'username' => $profile->username,
                'tagline' => $profile->tagline,
                'biography' => $profile->biography,
                'photo' => $profile->photo
                    ? asset('storage/uploads/users/original/'.$profile->photo)
                    : (! empty($user->avatar) ? $user->avatar : null),
                'cover_photo' => $profile->cover_photo ? asset('storage/uploads/users/original/'.$profile->cover_photo) : null,
                'website' => $profile->website,
                'company' => $profile->company,
                'country' => $profile->country,
                'city' => $profile->city,
                'social_media' => $profile->social_media,
            ],
            'stats' => [
                'years_active' => $yearsActive,
                'followers_count' => $followersCount,
                'following_count' => $followingCount,
            ],
        ]);
    }

    /** Best-effort viewer from a Bearer token; never fails the request. */
    private function resolveViewer(Request $request): ?User
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return null;
        }
        try {
            $user = JwtTokenService::fromConfig()->validateAccessToken($token);
        } catch (Throwable) {
            return null;
        }

        return $user && ($user->is_active ?? true) ? $user : null;
    }
}
