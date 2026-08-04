<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'order_id', 'last_message_at'];

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
