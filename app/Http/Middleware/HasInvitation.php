<?php

namespace App\Http\Middleware;

use App\Models\Invitation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasInvitation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('get')) {
            return $next($request);
        }

        if (! $request->filled('invitation_token') && ! $request->filled('code')) {
            return redirect()->route('requestInvitation')
                ->with('error', __('Please use a valid invitation link or code.'));
        }

        $invitation = Invitation::findActiveByRequest($request);

        if (! $invitation) {
            return redirect()->route('requestInvitation')
                ->with('error', __('Invalid or expired invitation.'));
        }

        if (! $invitation->is_reusable) {
            if (User::where('email', $invitation->email)->exists()) {
                return redirect()->route('requestInvitation')
                    ->with('error', __('This email is already registered.'));
            }

            if ($invitation->registered_at !== null) {
                return redirect()->route('login')
                    ->with('error', __('This invitation has already been used.'));
            }
        }

        $request->attributes->set('invitation', $invitation);

        return $next($request);
    }
}
