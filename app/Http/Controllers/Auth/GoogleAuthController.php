<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\Stores\StoreProvisioner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth.
     * New accounts created through Google are always merchants.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google OAuth callback.
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', __('Google sign-in failed. Please try again.'));
        }

        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();
        $name = $googleUser->getName() ?: $email;
        $avatar = $googleUser->getAvatar();

        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            // Existing Google-linked user: log in
            $user->update([
                'avatar' => $avatar,
                'last_login_at' => Carbon::now()->toDateTimeString(),
                'last_login_ip' => request()->getClientIp(),
            ]);
            $this->ensureProfile($user);
        } else {
            $user = User::where('email', $email)->first();

            if ($user) {
                // User exists but not linked: link Google and log in
                $user->update([
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'last_login_at' => Carbon::now()->toDateTimeString(),
                    'last_login_ip' => request()->getClientIp(),
                ]);
                $this->ensureProfile($user);
            } else {
                // New user: create account (register via Google)
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(32)),
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'last_login_at' => Carbon::now()->toDateTimeString(),
                    'last_login_ip' => request()->getClientIp(),
                ]);

                $user->assignRole('Merchant');
                $user->syncPermissions([]);

                // One Merchant = one Store: provision on Google sign-up.
                app(StoreProvisioner::class)->ensureFor($user);

                $profile = Profile::create([
                    'username' => generateUserName($email),
                    'user_id' => $user->id,
                    'gender' => 'male',
                    'active' => 0,
                    'private' => 0,
                    'online' => 1,
                ]);

            }
        }

        Auth::login($user, true);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function ensureProfile(User $user): void
    {
        $profile = $user->profile;
        if (! $profile) {
            Profile::create([
                'username' => generateUserName($user->email),
                'user_id' => $user->id,
                'gender' => 'male',
                'active' => 1,
                'private' => 0,
                'online' => 1,
            ]);
        } else {
            $profile->online = 1;
            $profile->save();
        }
    }
}
