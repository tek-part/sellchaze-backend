<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Profile;
use App\Providers\RouteServiceProvider;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return View
     */
    public function create()
    {
        addJavascriptFile('assets/js/custom/authentication/sign-in/general.js');

        return view('pages.auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     *
     * @return RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        $request->user()->update([
            'last_login_at' => Carbon::now()->toDateTimeString(),
            'last_login_ip' => $request->getClientIp(),
        ]);

        try {
            $profile = Profile::where('user_id', Auth::user()->id)->first();

            if ($profile === null) {
                $profile = new Profile;
                $profile->username = generateUserName(Auth::user()->email);
                $profile->gender = 'male';
                $profile->active = 1;
                $profile->private = 0;
                $profile->online = 1;
                $profile->user_id = Auth::user()->id;
                $profile->save();
            } else {
                $profile->online = 1;
                $profile->save();
            }
        } catch (\Throwable $e) {
            // Log but don't block login
            Log::warning('Profile update on login: '.$e->getMessage());
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     *
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request)
    {
        $profile = Profile::where('user_id', Auth::user()->id)->first();
        if ($profile) {
            $profile->online = 0;
            $profile->save();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
