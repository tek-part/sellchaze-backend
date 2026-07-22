<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationCreated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Bidirectional partner directory + invite flow for SPA.
 *
 * - A Merchant can invite a Supplier (invited_role=supplier).
 * - A Supplier can invite a Merchant (invited_role=merchant).
 * - An Admin can invite either side explicitly.
 */
class PartnersApiController extends Controller
{
    use AppliesMailConfig;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $suppliers = [];
        $merchants = [];

        if ($user->isMerchant() || $user->hasRole('Admin')) {
            $suppliers = $user->suppliersAsMerchant()->with('profile')->orderBy('name')->get()
                ->map(fn (User $u) => $this->serializeUser($u, 'supplier'))->values()->all();
        }
        if ($user->isSupplier() || $user->hasRole('Admin')) {
            $merchants = $user->merchantsAsSupplier()->with('profile')->orderBy('name')->get()
                ->map(fn (User $u) => $this->serializeUser($u, 'merchant'))->values()->all();
        }

        $pendingInvitations = Invitation::query()
            ->where(function ($q) use ($user) {
                $q->where('sender_user_id', $user->id)
                    ->orWhere('email', $user->email);
            })
            ->where('is_reusable', false)
            ->whereNull('registered_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (Invitation $i) use ($user) {
                $direction = ($i->sender_user_id === $user->id) ? 'outgoing' : 'incoming';
                $sender = null;
                if ($direction === 'incoming' && $i->sender_user_id) {
                    $s = User::find($i->sender_user_id);
                    if ($s) {
                        $sender = ['id' => $s->id, 'name' => $s->name, 'email' => $s->email];
                    }
                }
                return [
                    'id' => $i->id,
                    'email' => $i->email,
                    'invited_role' => $i->invited_role,
                    'status' => $i->status,
                    'direction' => $direction,
                    'sender' => $sender,
                    'invite_code' => $direction === 'incoming' ? $i->invite_code : null,
                    'created_at' => $i->created_at?->toIso8601String(),
                    'expires_at' => $i->expires_at?->toIso8601String(),
                ];
            })->values()->all();

        return response()->json([
            'suppliers' => $suppliers,
            'merchants' => $merchants,
            'pending_invitations' => $pendingInvitations,
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('Admin');
        $autoRole = null;
        if (! $isAdmin) {
            if ($user->isMerchant()) {
                $autoRole = 'supplier';
            } elseif ($user->isSupplier()) {
                $autoRole = 'merchant';
            }
        }

        $validated = $request->validate([
            'email' => ['required', 'email',
                Rule::unique('invitations', 'email')->where(function ($q) use ($user) {
                    return $q->where('sender_user_id', $user->id)->whereNull('registered_at');
                }),
            ],
            'invited_role' => [$autoRole ? 'sometimes' : 'required', Rule::in(['merchant', 'supplier'])],
        ]);

        $role = $autoRole ?? ($validated['invited_role'] ?? null);
        if (! $role) {
            return response()->json(['message' => 'invited_role is required.'], 422);
        }

        $invitation = new Invitation;
        $invitation->email = $validated['email'];
        $invitation->sender_user_id = $user->id;
        $invitation->invited_role = $role;
        $invitation->status = 'pending';
        $invitation->expires_at = now()->addDays(14);
        $invitation->generateInvitationToken();
        $invitation->generateInviteCode();
        $invitation->save();

        $this->applyMailConfigFromSettings();
        try {
            Notification::route('mail', $invitation->email)->notify(new InvitationCreated($invitation));
        } catch (\Throwable $e) {
            // Keep the invitation record even if mail fails — user can resend.
            report($e);
        }

        // If the invited email belongs to an existing user, add an in-app notification.
        try {
            $recipient = User::where('email', $invitation->email)->first();
            if ($recipient) {
                $recipient->notifications()->create([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => 'invitation',
                    'data' => [
                        'type' => 'invitation',
                        'invitation_id' => $invitation->id,
                        'sender_user_id' => $user->id,
                        'sender_name' => $user->name,
                        'invited_role' => $invitation->invited_role,
                        'invite_code' => $invitation->invite_code,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'invited_role' => $invitation->invited_role,
                'status' => $invitation->status,
                'invite_code' => $invitation->invite_code,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function acceptInvitation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $invitation = Invitation::findOrFail($id);

        if (strcasecmp($invitation->email, $user->email) !== 0) {
            return response()->json(['message' => 'You are not the recipient of this invitation.'], 403);
        }
        if (! $invitation->isUsable()) {
            return response()->json(['message' => 'Invitation is no longer valid.'], 422);
        }

        $senderId = (int) $invitation->sender_user_id;
        $receiverId = (int) $user->id;

        if ($invitation->invited_role === 'supplier') {
            // sender is merchant; recipient will act as supplier in the relation.
            $merchantId = $senderId;
            $supplierId = $receiverId;
        } else {
            // invited_role = merchant → sender is supplier; recipient is merchant.
            $merchantId = $receiverId;
            $supplierId = $senderId;
        }

        DB::table('merchant_supplier')->updateOrInsert(
            ['merchant_id' => $merchantId, 'supplier_id' => $supplierId],
            [
                'status' => 'accepted',
                'invited_by_user_id' => $senderId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $invitation->status = 'accepted';
        $invitation->receiver_user_id = $receiverId;
        $invitation->registered_at = now();
        $invitation->save();

        return response()->json(['ok' => true]);
    }

    public function rejectInvitation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $invitation = Invitation::findOrFail($id);

        if (strcasecmp($invitation->email, $user->email) !== 0) {
            return response()->json(['message' => 'You are not the recipient of this invitation.'], 403);
        }

        $invitation->status = 'rejected';
        $invitation->receiver_user_id = (int) $user->id;
        $invitation->registered_at = now();
        $invitation->save();

        return response()->json(['ok' => true]);
    }

    private function serializeUser(User $u, string $direction): array
    {
        $profile = $u->relationLoaded('profile') ? $u->profile : $u->profile;

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'direction' => $direction,
            'is_active' => (bool) ($u->is_active ?? true),
            'is_verified' => (bool) ($u->is_verified ?? false),
            'username' => $profile?->username,
            'avatar_url' => $profile?->photo
                ? asset('storage/uploads/users/thumbnails/'.$profile->photo)
                : (! empty($u->avatar) ? $u->avatar : null),
            'company' => $profile?->company,
            'country' => $profile?->country,
            'city' => $profile?->city,
        ];
    }
}
