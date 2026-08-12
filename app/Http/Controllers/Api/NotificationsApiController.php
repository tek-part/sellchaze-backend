<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(5, (int) $request->input('per_page', 20)));

        $paginator = $request->user()
            ->notifications()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (DatabaseNotification $n) => $this->serializeNotification($n))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var DatabaseNotification $notification */
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'message' => __('Notification marked as read.'),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $unread = $request->user()->unreadNotifications();
        // Avoid hydrating every notification and avoid an empty UPDATE (which
        // still takes a write lock on SQLite and creates needless DB traffic).
        if ((clone $unread)->limit(1)->exists()) {
            $unread->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => __('All notifications marked as read.'),
            'unread_count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
            'data' => $notification->data,
        ];
    }
}
