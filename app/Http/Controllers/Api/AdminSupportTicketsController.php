<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTickets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Platform support inbox (permission: support-tickets-manage). */
class AdminSupportTicketsController extends Controller
{
    public function __construct(private readonly SupportTickets $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'active'])],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
            'assigned' => ['nullable', Rule::in(['me', 'none'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = SupportTicket::query()->with(['store', 'owner', 'assignee'])->withCount('messages');
        if (($data['status'] ?? null) === 'active') {
            $query->whereIn('status', ['open', 'awaiting_merchant']);
        } elseif (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        foreach (['priority', 'category'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (($data['assigned'] ?? null) === 'me') {
            $query->where('assigned_to_user_id', $request->user()->id);
        } elseif (($data['assigned'] ?? null) === 'none') {
            $query->whereNull('assigned_to_user_id');
        }
        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn (Builder $w) => $w->where('subject', 'like', $like)->orWhere('reference', 'like', $like)
                ->orWhereHas('owner', fn (Builder $o) => $o->where('name', 'like', $like)->orWhere('email', 'like', $like)));
        }
        // Waiting on support first, then urgency, then the oldest reply.
        $query->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'awaiting_merchant' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('last_reply_at');
        $rows = $query->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($rows->items())->map(fn (SupportTicket $t) => $this->tickets->summary($t, true))->all(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
            'counts' => SupportTicket::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(int $ticket): JsonResponse
    {
        $model = SupportTicket::query()->findOrFail($ticket);
        if ($model->unreadForSupport()) {
            $model->forceFill(['support_read_at' => now()])->saveQuietly();
        }

        return response()->json([
            'data' => $this->tickets->detail($model, true),
            'agents' => User::permission('support-tickets-manage')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000'], ...SupportTicketsController::ATTACHMENT_RULES]);
        $model = SupportTicket::query()->findOrFail($ticket);
        $this->tickets->reply($model, $request->user(), true, $data['body'], $request->file('attachments', []));

        return response()->json(['data' => $this->tickets->detail($model->fresh(), true)], 201);
    }

    public function update(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'category' => ['sometimes', Rule::in(SupportTicket::CATEGORIES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ]);
        $model = SupportTicket::query()->findOrFail($ticket);
        if (array_key_exists('assigned_to_user_id', $data) && $data['assigned_to_user_id'] !== null) {
            abort_unless(User::query()->whereKey($data['assigned_to_user_id'])->first()?->can('support-tickets-manage'), 422, 'Assign the ticket to a support agent.');
        }
        $model->fill(collect($data)->except('status')->all())->save();
        if (isset($data['status'])) {
            $this->tickets->changeStatus($model, $data['status'], true);
        }

        return response()->json(['data' => $this->tickets->detail($model->fresh(), true)]);
    }

    public function attachment(int $ticket, int $message, int $index): StreamedResponse
    {
        return SupportTicketsController::download($this->tickets, SupportTicket::query()->findOrFail($ticket), $message, $index);
    }
}
