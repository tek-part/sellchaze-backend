<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSupplier extends Model
{
    protected $table = 'merchant_supplier';

    protected $fillable = [
        'merchant_id',
        'supplier_id',
        'status',
        'invited_by_user_id',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public static function createPartnershipFromInvitation(Invitation $invitation, User $newUser): void
    {
        $senderId = (int) $invitation->sender_user_id;
        $sender = User::find($senderId);
        if (! $sender) {
            return;
        }

        if ($invitation->invited_role === 'supplier' && $sender->hasRole('Merchant')) {
            static::query()->firstOrCreate(
                [
                    'merchant_id' => $senderId,
                    'supplier_id' => $newUser->id,
                ],
                [
                    'status' => 'accepted',
                    'invited_by_user_id' => $senderId,
                ]
            );
        } elseif ($invitation->invited_role === 'merchant' && $sender->hasRole('Supplier')) {
            static::query()->firstOrCreate(
                [
                    'merchant_id' => $newUser->id,
                    'supplier_id' => $senderId,
                ],
                [
                    'status' => 'accepted',
                    'invited_by_user_id' => $senderId,
                ]
            );
        }
    }
}
