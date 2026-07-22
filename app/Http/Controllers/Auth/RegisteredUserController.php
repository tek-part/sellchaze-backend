<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\MerchantSupplier;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:invitations-send-request', ['only' => ['requestInvitation']]);
    }

    public function create(Request $request)
    {
        $type = $request->query('type');
        if (!in_array($type, ['merchant', 'supplier'], true)) {
            return redirect()->route('register.choose-type');
        }

        return view('pages.auth.register', compact('type'));
    }

    public function store(Request $request)
    {
        $invitation = null;
        if ($request->filled('invitation_token')) {
            $invitation = Invitation::query()
                ->where('invitation_token', $request->invitation_token)
                ->first();
        } elseif ($request->filled('invite_code')) {
            $invitation = Invitation::query()
                ->where('invite_code', strtoupper($request->invite_code))
                ->first();
        }

        if ($invitation) {
            if (! $invitation->isUsable()) {
                return redirect()->route('login')->with('error', __('This invitation is no longer valid.'));
            }
            if (! $invitation->is_reusable) {
                $request->merge(['email' => $invitation->email]);
            }
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (User::where('email', '=', $request->email)->exists()) {
            session()->flash('error', __('Sorry but this email already exists.'));

            return redirect()->back();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if ($invitation) {
            if (! $invitation->is_reusable) {
                $invitation->registered_at = now();
                $invitation->receiver_user_id = $user->id;
                $invitation->status = 'accepted';
                $invitation->save();
            }

            if ($invitation->invited_role === 'supplier') {
                $user->assignRole('Supplier');
                $user->syncPermissions([]);
            } elseif ($invitation->invited_role === 'merchant') {
                $user->assignRole('Merchant');
                $user->syncPermissions([]);
            } else {
                $user->assignRole('Merchant');
                $permIds = null;
                if ($request->filled('permissions')) {
                    $permIds = @unserialize(base64_decode($request->permissions));
                }
                if (! is_array($permIds) && ! empty($invitation->permissions)) {
                    $permIds = @unserialize($invitation->permissions);
                }
                if (is_array($permIds) && count($permIds) > 0) {
                    $user->syncPermissions($permIds);
                } else {
                    $user->syncPermissions([]);
                }
            }

            MerchantSupplier::createPartnershipFromInvitation($invitation, $user);
        } else {
            $type = $request->input('type', 'merchant');
            $role = $type === 'supplier' ? 'Supplier' : 'Merchant';
            $user->assignRole($role);
            $user->syncPermissions([]);
        }

        // One Merchant/Supplier = one Store: provision on account creation
        // (idempotent — no-op if the account is not an owner or already has one).
        app(\App\Services\Stores\StoreProvisioner::class)->ensureFor($user->fresh() ?? $user);

        event(new Registered($user));

        $profile = new Request;
        $profile->username = generateUserName($request->email);
        $profile->created_at = date('Y-m-d H:i:s');
        $profile->updated_at = date('Y-m-d H:i:s');

        generateProfle($profile, $user->id);

        Auth::login($user);

        $request->user()->update([
            'last_login_at' => Carbon::now()->toDateTimeString(),
            'last_login_ip' => $request->getClientIp(),
        ]);

        return redirect(RouteServiceProvider::HOME);
    }

    public function requestInvitation()
    {
        return view('pages.auth.request');
    }

    public function showRegistrationForm(Request $request)
    {
        if (Auth::check()) {
            return redirect(RouteServiceProvider::HOME);
        }

        $invitation = $request->attributes->get('invitation');
        if (! $invitation instanceof Invitation) {
            $invitation = Invitation::findActiveByRequest($request);
        }

        if (! $invitation) {
            return redirect()->route('requestInvitation')
                ->with('error', __('Invalid invitation.'));
        }

        $email = $invitation->email;
        $invitationToken = $invitation->invitation_token;
        $inviteCode = $invitation->invite_code;
        $legacyPermissionsQuery = $request->get('permissions');
        $isReusableInvite = $invitation->is_reusable;

        return view('pages.auth.request-register', compact('email', 'invitationToken', 'inviteCode', 'legacyPermissionsQuery', 'isReusableInvite'));
    }
}
