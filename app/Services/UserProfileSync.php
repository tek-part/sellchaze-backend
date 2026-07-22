<?php

namespace App\Services;

use App\Models\User;

class UserProfileSync
{
    /**
     * @param  array<string, mixed>  $profileData
     */
    public static function sync(User $user, array $profileData): void
    {
        $keys = [
            'username', 'biography', 'photo', 'cover_photo', 'website', 'tagline', 'is_public',
            'company', 'country', 'city', 'address',
            'gender', 'phone', 'whatsapp', 'birthdate', 'social_media',
        ];
        $allowed = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $profileData)) {
                $allowed[$key] = $profileData[$key];
            }
        }

        if ($allowed === []) {
            return;
        }

        if ($user->profile) {
            $user->profile->update($allowed);
        } else {
            if (empty($allowed['username'])) {
                $allowed['username'] = 'user_'.$user->id;
            }
            $user->profile()->create($allowed);
        }
    }
}
