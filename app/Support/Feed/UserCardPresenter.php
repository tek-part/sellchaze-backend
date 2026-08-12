<?php

namespace App\Support\Feed;

use App\Models\User;

/**
 * The compact user row the social surfaces render: follower lists, reaction
 * viewers and people search all share this one shape. Expects `profile` and
 * `roles` to be eager-loaded on the user.
 */
class UserCardPresenter
{
    /** @return array<string, mixed> */
    public static function make(User $user, bool $isFollowing = false, bool $followsYou = false): array
    {
        $profile = $user->profile;
        $public = $profile && $profile->is_public;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'company' => $profile?->company,
            'username' => $public ? $profile->username : null,
            'is_verified' => (bool) ($user->is_verified ?? false),
            'photo' => $profile?->photo
                ? asset('storage/uploads/users/original/'.$profile->photo)
                : (! empty($user->avatar) ? $user->avatar : null),
            'role' => $user->communityRole(),
            'is_following' => $isFollowing,
            'follows_you' => $followsYou,
        ];
    }
}
