<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'invitation_token',
        'invite_code',
        'invited_role',
        'status',
        'is_reusable',
        'expires_at',
        'permissions',
        'registered_at',
        'sender_user_id',
        'receiver_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'registered_at' => 'datetime',
            'is_reusable' => 'boolean',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_user_id');
    }

    public function generateInvitationToken(): void
    {
        $this->invitation_token = substr(md5(rand(0, 9).$this->email.time()), 0, 32);
    }

    public function generateInviteCode(): void
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        } while (static::query()->where('invite_code', $code)->exists());

        $this->invite_code = $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        if ($this->is_reusable) {
            if ($this->status === 'cancelled') {
                return false;
            }
            if ($this->isExpired()) {
                return false;
            }

            return true;
        }

        if ($this->registered_at !== null) {
            return false;
        }
        if ($this->status === 'cancelled') {
            return false;
        }
        if ($this->isExpired()) {
            return false;
        }

        return true;
    }

    public static function findActiveByRequest(Request $request): ?self
    {
        if ($request->filled('invitation_token')) {
            $inv = static::query()->where('invitation_token', $request->get('invitation_token'))->first();
        } elseif ($request->filled('code')) {
            $inv = static::query()->where('invite_code', strtoupper($request->get('code')))->first();
        } else {
            return null;
        }

        if (! $inv || ! $inv->isUsable()) {
            return null;
        }

        return $inv;
    }

    public function getLink(?array $permissionIds = null): string
    {
        $permissions = '';
        if ($permissionIds !== null && count($permissionIds) > 0) {
            $permissions = '&permissions='.base64_encode(serialize($permissionIds));
        }

        $url = route('register.invitation', ['invitation_token' => $this->invitation_token]);

        return $url.$permissions;
    }

    public function getRegisterUrlAttribute(): string
    {
        return $this->getLink();
    }

    /**
     * One reusable link/code per merchant or supplier: anyone who registers through it is linked to the sender.
     * Merchants invite suppliers; suppliers invite merchants.
     */
    public static function ensureReusableShareForSender(User $user): ?self
    {
        $invitedRole = null;
        if ($user->isMerchant() && $user->isSupplier()) {
            $invitedRole = 'supplier';
        } elseif ($user->isMerchant()) {
            $invitedRole = 'supplier';
        } elseif ($user->isSupplier()) {
            $invitedRole = 'merchant';
        }

        if ($invitedRole === null) {
            return null;
        }

        $existing = static::query()
            ->where('sender_user_id', $user->id)
            ->where('is_reusable', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $inv = new static;
        $inv->email = 'share.'.$user->id.'.'.bin2hex(random_bytes(6)).'@invite.reusable';
        $inv->sender_user_id = $user->id;
        $inv->invited_role = $invitedRole;
        $inv->status = 'pending';
        $inv->is_reusable = true;
        $inv->expires_at = null;
        $inv->generateInvitationToken();
        $inv->generateInviteCode();
        $inv->save();

        return $inv;
    }
}
