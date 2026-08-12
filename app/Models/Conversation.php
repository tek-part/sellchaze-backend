<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'order_id', 'procurement_request_id', 'procurement_order_id',
        'buyer_organization_id', 'supplier_organization_id', 'last_message_at',
    ];

    protected $casts = ['last_message_at' => 'datetime'];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /** @return BelongsTo<ProcurementRequest, $this> */
    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class);
    }

    /** @return BelongsTo<ProcurementOrder, $this> */
    public function procurementOrder(): BelongsTo
    {
        return $this->belongsTo(ProcurementOrder::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function buyerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function supplierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    /**
     * Find (or create) the 1:1 direct conversation between two users.
     */
    public static function between(int $userA, int $userB): self
    {
        $existing = self::query()
            ->where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userA))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userB))
            ->first();

        if ($existing) {
            return $existing;
        }

        $conversation = self::create(['type' => 'direct']);
        $conversation->participants()->createMany([
            ['user_id' => $userA],
            ['user_id' => $userB],
        ]);

        return $conversation;
    }
}
