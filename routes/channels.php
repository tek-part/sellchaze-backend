<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private direct-chat channel: only conversation participants may subscribe.
Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    return Conversation::query()
        ->where('id', $conversationId)
        ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
        ->exists();
});
