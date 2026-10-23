<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\Rbac\UserScope;
use App\Services\Support\SupportTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Merchant side of support: an account sees only its own tickets (employees see their merchant's). */
class SupportTicketsController extends Controller
{
    public const ATTACHMENT_RULES = ['attachments' => ['sometimes', 'array', 'max:3'], 'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']];

    public function __construct(private readonly SupportTickets $tickets) {}

    private function find(Request $request, int $id): SupportTicket
    {
        return SupportTicket::query()
            ->where('owner_user_id', UserScope::effectiveMerchantUserId($request->user()))
            ->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'active'])]]);
        $query = SupportTicket::query()
            ->where('owner_user_id', UserScope::effectiveMerchantUserId($request->user()))
            ->with(['store', 'assignee'])->withCount('messages')
            ->orderByDesc('last_reply_at')->orderByDesc('id');
        if (($data['status'] ?? null) === 'active') {
            $query->whereIn('status', ['open', 'awaiting_merchant']);
        } elseif (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $rows = $query->paginate(min(50, max(5, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($rows->items())->map(fn (SupportTicket $t) => $this->tickets->summary($t, false))->all(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            ...self::ATTACHMENT_RULES,
        ]);
        $ticket = $this->tickets->open($request->user(), $data, $request->file('attachments', []));

        return response()->json(['data' => $this->tickets->detail($ticket, false)], 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $model = $this->find($request, $ticket);
        if ($model->unreadForMerchant()) {
            $model->forceFill(['merchant_read_at' => now()])->saveQuietly();
        }

        return response()->json(['data' => $this->tickets->detail($model, false)]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000'], ...self::ATTACHMENT_RULES]);
        $model = $this->find($request, $ticket);
        $this->tickets->reply($model, $request->user(), false, $data['body'], $request->file('attachments', []));

        return response()->json(['data' => $this->tickets->detail($model->fresh(), false)], 201);
    }

    public function close(Request $request, int $ticket): JsonResponse
    {
        $model = $this->find($request, $ticket);
        $this->tickets->changeStatus($model, 'closed', false);

        return response()->json(['data' => $this->tickets->detail($model->fresh(), false)]);
    }

    public function attachment(Request $request, int $ticket, int $message, int $index): StreamedResponse
    {
        $model = $this->find($request, $ticket);

        return $this->download($this->tickets, $model, $message, $index);
    }

    public static function download(SupportTickets $tickets, SupportTicket $ticket, int $messageId, int $index): StreamedResponse
    {
        $message = SupportTicketMessage::query()->where('support_ticket_id', $ticket->id)->findOrFail($messageId);
        $file = $tickets->attachmentPath($message, $index);
        abort_unless($file, 404);

        return Storage::disk(SupportTickets::DISK)->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
