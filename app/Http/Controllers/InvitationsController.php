<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class InvitationsController extends Controller
{
    use AppliesMailConfig;

    public function __construct()
    {
        $this->middleware('permission:invitations-list', ['only' => ['index']]);
        $this->middleware('permission:invitations-send-request', ['only' => ['store']]);
        $this->middleware('permission:invitations-delete', ['only' => ['destroy', 'bulkDestroy']]);
    }

    public function index()
    {
        $user = Auth::user();

        $partnerSuppliers = collect();
        $partnerMerchants = collect();
        $sentInvitations = null;
        $allInvitations = null;

        $shareInvitation = Invitation::ensureReusableShareForSender($user);

        if ($user->hasRole('Admin')) {
            $allInvitations = Invitation::query()->with(['sender', 'receiver'])->orderByDesc('id')->limit(2000)->get();
        } else {
            $sentInvitations = Invitation::query()
                ->where('sender_user_id', $user->id)
                ->where('is_reusable', false)
                ->orderByDesc('id')
                ->limit(2000)
                ->get();
        }

        if ($user->isMerchant()) {
            $partnerSuppliers = $user->suppliersAsMerchant()->orderBy('name')->get();
        }

        if ($user->isSupplier()) {
            $partnerMerchants = $user->merchantsAsSupplier()->orderBy('name')->get();
        }

        return view('pages.invitations.index', compact(
            'sentInvitations',
            'partnerSuppliers',
            'partnerMerchants',
            'allInvitations',
            'shareInvitation'
        ))
            ->with('title', __('Invitations'))
            ->with('breadcrumb', __('Invitations'));
    }

    public function store(StoreInvitationRequest $request)
    {
        $invitation = new Invitation;
        $invitation->email = $request->email;
        $invitation->sender_user_id = Auth::id();
        $invitation->invited_role = $request->invited_role;
        $invitation->status = 'pending';
        $invitation->expires_at = now()->addDays(14);
        $invitation->generateInvitationToken();
        $invitation->generateInviteCode();

        if ($request->filled('permissions')) {
            $invitation->permissions = serialize($request->permissions);
        }

        $invitation->save();

        $this->applyMailConfigFromSettings();

        $legacyPermissions = $request->input('permissions');
        Notification::route('mail', $request->email)
            ->notify(new InvitationCreated($invitation, is_array($legacyPermissions) ? $legacyPermissions : null));

        return redirect()->route('invitations.index')
            ->with('success', __('Invitation sent successfully.'));
    }

    public function destroy(Request $request, Invitation $invitation)
    {
        $user = Auth::user();

        if (! $user->hasRole('Admin') && (int) $invitation->sender_user_id !== (int) $user->id) {
            abort(403);
        }

        $invitation->delete();

        return redirect()->route('invitations.index')->with('success', __('Invitation deleted.'));
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:invitations,id',
        ]);
        $user = Auth::user();
        $count = 0;
        foreach ($validated['ids'] as $id) {
            $inv = Invitation::find($id);
            if (!$inv) continue;
            if (!$user->hasRole('Admin') && (int) $inv->sender_user_id !== (int) $user->id) continue;
            if ($inv->registered_at) {
                continue;
            }
            $inv->delete();
            $count++;
        }
        return redirect()->route('invitations.index')->with('success', __(':count invitation(s) deleted.', ['count' => $count]));
    }
}
