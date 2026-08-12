<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserSafetyRelation;
use App\Notifications\NewMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Direct (user-to-user) chat backed by Pusher broadcasting.
 */
class ChatApiController extends Controller
{
    /** List the authenticated user's conversations with last message + unread count. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $me->id))
            ->with([
                'latestMessage', 'users:id,name,avatar',
                'buyerOrganization:id,name,slug', 'supplierOrganization:id,name,slug',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $data = $conversations->map(function (Conversation $c) use ($me) {
            $other = $c->users->firstWhere('id', '!=', $me->id);
            $mine = $c->participants->firstWhere('user_id', $me->id);
            $unread = Message::query()
                ->where('conversation_id', $c->id)
                ->where('user_id', '!=', $me->id)
                ->when($mine && $mine->last_read_at, fn ($q) => $q->where('created_at', '>', $mine->last_read_at))
                ->count();

            return [
                'id' => $c->id,
                'type' => $c->type,
                'other_user' => $other ? [
                    'id' => $other->id,
                    'name' => $other->name,
                    'avatar' => $other->avatar,
                ] : null,
                'last_message' => $c->latestMessage ? [
                    'body' => $c->latestMessage->body,
                    'user_id' => $c->latestMessage->user_id,
                    'created_at' => $c->latestMessage->created_at?->toIso8601String(),
                ] : null,
                'unread' => $unread,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'context' => $c->type === 'procurement' ? [
                    'procurement_request_id' => $c->procurement_request_id,
                    'procurement_order_id' => $c->procurement_order_id,
                    'buyer_organization' => $c->buyerOrganization,
                    'supplier_organization' => $c->supplierOrganization,
                ] : null,
            ];
        });

        return response()->json(['data' => $data->values()->all()]);
    }

    /** Total unread chat messages for the badge on the chat button. */
    public function unreadCount(Request $request): JsonResponse
    {
        $me = $request->user();

        $total = (int) \DB::table('conversation_participants as cp')
            ->join('messages as m', 'm.conversation_id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $me->id)
            ->where('m.user_id', '!=', $me->id)
            ->where(function ($q) {
                $q->whereNull('cp.last_read_at')->orWhereColumn('m.created_at', '>', 'cp.last_read_at');
            })
            ->count();

        return response()->json(['unread' => $total]);
    }

    /** Start (or fetch) a 1:1 conversation with another user. */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        if ((int) $data['user_id'] === (int) $me->id) {
            return response()->json(['message' => 'Cannot start a conversation with yourself'], 422);
        }

        $blocked = UserSafetyRelation::query()
            ->where('type', 'block')
            ->where(function ($query) use ($me, $data): void {
                $query->where(fn ($pair) => $pair->where('actor_user_id', $me->id)->where('target_user_id', $data['user_id']))
                    ->orWhere(fn ($pair) => $pair->where('actor_user_id', $data['user_id'])->where('target_user_id', $me->id));
            })->exists();
        if ($blocked) {
            return response()->json(['message' => 'Conversation is unavailable.'], 403);
        }

        $conversation = Conversation::between($me->id, (int) $data['user_id']);
        $other = User::query()->find($data['user_id']);

        return response()->json([
            'id' => $conversation->id,
            'type' => $conversation->type,
            'other_user' => ['id' => $other->id, 'name' => $other->name, 'avatar' => $other->avatar],
        ], 201);
    }

    /** Paginated messages for a conversation (and mark them read). */
    public function messages(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->authorizeMember($id, $me->id);

        $perPage = (int) min(100, max(10, (int) $request->query('per_page', 30)));
        $rows = Message::query()
            ->where('conversation_id', $id)
            ->with('user:id,name,avatar')
            ->orderByDesc('id')
            ->paginate($perPage);

        $this->markRead($conversation, $me->id);

        $items = collect($rows->items())->map(fn (Message $m) => [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'body' => $m->body,
            'mine' => (int) $m->user_id === (int) $me->id,
            'user' => ['id' => $m->user_id, 'name' => $m->user?->name, 'avatar' => $m->user?->avatar],
            'created_at' => $m->created_at?->toIso8601String(),
        ])->reverse()->values();

        return response()->json([
            'data' => $items->all(),
            'meta' => [
                'total' => $rows->total(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /** Send a message → persist + broadcast over Pusher. */
    public function send(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->authorizeMember($id, $me->id);
        $otherIds = $conversation->participants()->where('user_id', '!=', $me->id)->pluck('user_id');
        $blocked = UserSafetyRelation::query()->where('type', 'block')
            ->where(function ($query) use ($me, $otherIds): void {
                $query->where(fn ($pair) => $pair->where('actor_user_id', $me->id)->whereIn('target_user_id', $otherIds))
                    ->orWhere(fn ($pair) => $pair->whereIn('actor_user_id', $otherIds)->where('target_user_id', $me->id));
            })->exists();
        if ($blocked) {
            return response()->json(['message' => 'Conversation is unavailable.'], 403);
        }
        $data = $request->validate(['body' => 'required|string|max:5000']);

        // Recipients = the other participants. Capture whether each already had
        // unread messages BEFORE this one (to email only on the first new message).
        $recipients = $conversation->participants
            ->where('user_id', '!=', $me->id)
            ->map(function ($p) use ($conversation) {
                $hadUnread = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', '!=', $p->user_id)
                    ->when($p->last_read_at, fn ($q) => $q->where('created_at', '>', $p->last_read_at))
                    ->exists();

                return ['user_id' => $p->user_id, 'should_email' => ! $hadUnread];
            });

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'body' => trim($data['body']),
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);
        $this->markRead($conversation, $me->id);

        broadcast(new MessageSent($message))->toOthers();

        // Notify recipients (bell + real-time + email). Never let a notification
        // or mail failure break message delivery.
        try {
            $users = User::query()->whereIn('id', $recipients->pluck('user_id'))->get()->keyBy('id');
            foreach ($recipients as $r) {
                $user = $users->get($r['user_id']);
                if ($user) {
                    $user->notify(new NewMessageNotification($message, $r['should_email']));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Chat notification failed: '.$e->getMessage());
        }

        return response()->json([
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'body' => $message->body,
            'mine' => true,
            'user' => ['id' => $me->id, 'name' => $me->name, 'avatar' => $me->avatar],
            'created_at' => $message->created_at?->toIso8601String(),
        ], 201);
    }

    /** Mark a conversation as read. */
    public function read(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $conversation = $this->authorizeMember($id, $me->id);
        $this->markRead($conversation, $me->id);

        return response()->json(['ok' => true]);
    }

    private function authorizeMember(int $conversationId, int $userId): Conversation
    {
        $conversation = Conversation::query()
            ->with('participants')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->findOrFail($conversationId);

        return $conversation;
    }

    private function markRead(Conversation $conversation, int $userId): void
    {
        $conversation->participants()
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);
    }
}
