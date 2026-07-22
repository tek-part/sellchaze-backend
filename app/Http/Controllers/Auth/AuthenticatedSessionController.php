<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Profile;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        addJavascriptFile('assets/js/custom/authentication/sign-in/general.js');

        return view('pages.auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @param  \App\Http\Requests\Auth\LoginRequest  $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        $request->user()->update([
            'last_login_at' => Carbon::now()->toDateTimeString(),
            'last_login_ip' => $request->getClientIp()
        ]);

        try {
            $profile = Profile::where('user_id', Auth::user()->id)->first();

            if ($profile === null) {
                $profile = new Profile;
                $profile->username = generateUserName(Auth::user()->email);
                $profile->gender   = 'male';
                $profile->active   = 1;
                $profile->private  = 0;
                $profile->online   = 1;
                $profile->user_id  = Auth::user()->id;
                $profile->save();
            } else {
                $profile->online = 1;
                $profile->save();
            }
        } catch (\Throwable $e) {
            // Log but don't block login
            \Illuminate\Support\Facades\Log::warning('Profile update on login: ' . $e->getMessage());
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\RedirectResponse
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