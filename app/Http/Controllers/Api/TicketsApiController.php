<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Controllers\Controller;
use App\Mail\TicketCreatedMail;
use App\Mail\TicketReplyMail;
use App\Mail\TicketStatusChangedMail;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\TicketAction;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketCreatedDatabaseNotification;
use App\Notifications\TicketReplyDatabaseNotification;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketsApiController extends Controller
{
    use AppliesMailConfig;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = OrderTicket::with([
            'order:id,code,status',
            'requester:id,name',
            'assignee:id,name',
        ]);

        if (UserScope::isAdmin($user)) {
            // no scoping
        } elseif (UserScope::isSupplier($user)) {
            $supplierId = (int) $user->id;
            $query->where(function ($q) use ($supplierId) {
                $q->where('requested_by', $supplierId)
                    ->orWhereHas('order.suppliers', fn ($sq) => $sq->where('supplier', $supplierId));
            });
        } else {
            $merchantId = UserScope::effectiveMerchantUserId($user);
            $query->where(function ($q) use ($merchantId) {
                $q->whereHas('order', function ($oq) use ($merchantId) {
                    $oq->where('user_id', $merchantId)
                        ->orWhereHas('suppliers', fn ($sq) => $sq->where('customer', $merchantId));
                });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('notes', 'like', $term)
                    ->orWhere('type', 'like', $term)
                    ->orWhereHas('order', fn ($oq) => $oq->where('code', 'like', $term));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('order_tickets.created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('order_tickets.created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $data = $paginator->getCollection()->map(static function (OrderTicket $t) {
            return [
                'id' => $t->id,
                'type' => $t->type,
                'status' => $t->status,
                'notes' => $t->notes,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
                'order' => $t->order ? [
                    'id' => $t->order->id,
                    'code' => $t->order->code,
                    'status' => $t->order->status,
                ] : null,
                'requester' => $t->requester ? [
                    'id' => $t->requester->id,
                    'name' => $t->requester->name,
                ] : null,
                'assignee' => $t->assignee ? [
                    'id' => $t->assignee->id,
                    'name' => $t->assignee->name,
                ] : null,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_code' => ['required_without:order_id', 'string', 'max:64'],
            'order_id' => ['required_without:order_code', 'integer', 'exists:orders,id'],
            'type' => ['required', 'in:replacement,return,other'],
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $order = isset($validated['order_id'])
            ? Order::query()->findOrFail($validated['order_id'])
            : Order::query()->where('code', $validated['order_code'])->firstOrFail();

        $user = $request->user();
        if (! $this->userCanAccessOrder($user, $order)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $ticket = OrderTicket::create([
            'order_id' => $order->id,
            'type' => $validated['type'],
            'status' => 'awaiting_supplier',
            'requested_by' => $user->id,
            'notes' => $validated['notes'],
        ]);

        try {
            $toNotify = collect();
            if ($order->user_id && (int) $order->user_id !== (int) $user->id) {
                $toNotify->push((int) $order->user_id);
            }
            foreach (User::role('Admin')->get() as $a) {
                if ((int) $a->id !== (int) $user->id) {
                    $toNotify->push((int) $a->id);
                }
            }
            foreach ($toNotify->unique() as $uid) {
                $target = User::find($uid);
                if ($target) {
                    $target->notify(new TicketCreatedDatabaseNotification([
                        'ticket_id' => $ticket->id,
                        'order_id' => $order->id,
                        'order_code' => $order->code,
                    ]));
                }
            }
        } catch (\Throwable) {
            /* optional notifications */
        }

        try {
            $this->applyMailConfigFromSettings();
            $order->load('suppliers');
            foreach ($order->suppliers as $os) {
                $supplier = User::find($os->supplier);
                if ($supplier && $supplier->email) {
                    Mail::mailer('smtp')->to($supplier->email)->send(new TicketCreatedMail($ticket));
                }
            }
        } catch (\Throwable) {
            /* optional mail */
        }

        return response()->json([
            'data' => [
                'id' => $ticket->id,
                'type' => $ticket->type,
                'status' => $ticket->status,
            ],
        ], 201);
    }

    public function show(Request $request, OrderTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanAccessTicket($user, $ticket)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ticket->load([
            'order.suppliers.supplier:id,name,email',
            'requester:id,name,email',
            'assignee:id,name,email',
            'messages.user:id,name',
            'actions.performer:id,name',
        ]);

        return response()->json([
            'data' => $this->formatTicketDetail($ticket, $user),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function storeMessage(Request $request, OrderTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanAccessTicket($user, $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            /* max is in KB (20480 = 20 MB). Avoid strict mimes — iPhone HEIC, some MP4/MOV, etc. fail extension checks. */
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $body = trim((string) $request->input('body', ''));
        $files = $request->file('attachments', []) ?: [];
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        $files = is_array($files) ? array_values(array_filter($files)) : [];

        if ($body === '' && $files === []) {
            return response()->json(['message' => 'Please enter a message or attach at least one file.'], 422);
        }

        $ticket->loadMissing(['order.suppliers.supplier', 'requester', 'assignee']);
        $order = $ticket->order;
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 422);
        }

        $attachmentPayload = $this->storeTicketMessageFiles($files);

        if ($body === '' && $files !== [] && $attachmentPayload === []) {
            return response()->json([
                'message' => 'Could not store attachments. Check file size (max 20 MB each) and server upload limits (PHP upload_max_filesize / post_max_size).',
            ], 422);
        }

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $body,
            'attachments' => $attachmentPayload === [] ? null : $attachmentPayload,
        ]);

        $replierId = (int) $user->id;
        $supplierIds = $order->suppliers->pluck('supplier')->filter()->unique();
        $isSupplierReply = $supplierIds->contains($replierId);

        if ($isSupplierReply) {
            $ticket->update(['status' => 'supplier_responded']);
        } else {
            $ticket->update(['status' => 'awaiting_supplier']);
        }

        $message->load('user:id,name');

        try {
            $this->applyMailConfigFromSettings();
            $emails = collect();
            if ($isSupplierReply) {
                foreach ([$ticket->requester, $ticket->assignee] as $recip) {
                    if ($recip && $recip->email) {
                        $emails->push(['email' => $recip->email, 'name' => $recip->name]);
                    }
                }
            } else {
                foreach ($order->suppliers as $os) {
                    $supplier = $os->supplier ?? User::find($os->supplier);
                    if ($supplier && $supplier->email) {
                        $emails->push(['email' => $supplier->email, 'name' => $supplier->name]);
                    }
                }
            }
            foreach ($emails->unique('email') as $r) {
                Mail::mailer('smtp')->to($r['email'])->queue(new TicketReplyMail($ticket->fresh(), $message->fresh(), $r['name'] ?? $r['email']));
            }
        } catch (\Throwable) {
            /* optional mail */
        }

        try {
            $preview = $body !== '' ? Str::limit($body, 120) : __('(attachment)');
            $recipientIds = collect();
            if ($isSupplierReply) {
                if ($ticket->requested_by) {
                    $recipientIds->push((int) $ticket->requested_by);
                }
                if ($ticket->assigned_to) {
                    $recipientIds->push((int) $ticket->assigned_to);
                }
            } else {
                foreach ($order->suppliers as $os) {
                    if ($os->supplier) {
                        $recipientIds->push((int) $os->supplier);
                    }
                }
            }
            foreach ($recipientIds->unique()->filter(fn ($id) => (int) $id !== (int) $user->id) as $rid) {
                $ru = User::find($rid);
                if ($ru) {
                    $ru->notify(new TicketReplyDatabaseNotification([
                        'ticket_id' => $ticket->id,
                        'order_id' => $order->id,
                        'order_code' => $order->code,
                        'message_id' => $message->id,
                        'preview' => $preview,
                    ]));
                }
            }
        } catch (\Throwable) {
            /* optional notifications */
        }

        $order->loadMissing('suppliers');

        return response()->json([
            'data' => [
                'id' => $message->id,
                'body' => $message->body,
                'attachments' => $message->attachments ?? [],
                'created_at' => $message->created_at,
                'user' => $message->user ? [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                ] : null,
                'sender_role' => $this->participantRoleForOrder($message->user, $order),
            ],
        ], 201);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, url: string, mime: string, kind: string}>
     */
    private function storeTicketMessageFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $original = $file->getClientOriginalName() ?: 'file';
            $ext = strtolower((string) $file->getClientOriginalExtension());

            if ($mime === 'application/octet-stream' || $mime === '') {
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'svg'], true)) {
                    $mime = 'image/'.$ext;
                } elseif (in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'], true)) {
                    $mime = 'video/'.$ext;
                }
            }

            try {
                $storedPath = $file->store('ticket-messages', 'public');
            } catch (\Throwable) {
                continue;
            }

            $url = Storage::disk('public')->url($storedPath);
            if (! str_starts_with((string) $url, 'http')) {
                $url = url($url);
            }

            $kind = 'file';
            if (str_starts_with($mime, 'image/') || in_array($ext, ['heic', 'heif'], true)) {
                $kind = 'image';
            } elseif (str_starts_with($mime, 'video/')) {
                $kind = 'video';
            }

            $out[] = [
                'name' => $original,
                'url' => $url,
                'mime' => $mime,
                'kind' => $kind,
            ];
        }

        return $out;
    }

    public function updateTicket(Request $request, OrderTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanAccessTicket($user, $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $ticket->loadMissing('order');
        $order = $ticket->order;
        if ($order && $this->participantRoleForOrder($user, $order) === 'supplier' && ! UserScope::isAdmin($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:open,awaiting_supplier,supplier_responded,in_progress,resolved,closed'],
        ]);

        $oldStatus = $ticket->status;
        $ticket->update(['status' => $validated['status']]);
        $ticket->load([
            'order.suppliers.supplier:id,name,email',
            'requester:id,name,email',
            'assignee:id,name,email',
            'messages.user:id,name',
            'actions.performer:id,name',
        ]);

        try {
            $this->applyMailConfigFromSettings();
            $emails = collect();
            foreach ([$ticket->requester, $ticket->assignee] as $recip) {
                if ($recip && $recip->email) {
                    $emails->push(['email' => $recip->email, 'name' => $recip->name]);
                }
            }
            if ($ticket->order) {
                foreach ($ticket->order->suppliers as $os) {
                    $supplier = $os->supplier ?? User::find($os->supplier);
                    if ($supplier && $supplier->email) {
                        $emails->push(['email' => $supplier->email, 'name' => $supplier->name]);
                    }
                }
            }
            foreach ($emails->unique('email') as $r) {
                Mail::mailer('smtp')->to($r['email'])->queue(new TicketStatusChangedMail($ticket->fresh(), $oldStatus, $r['name'] ?? $r['email']));
            }
        } catch (\Throwable) {
            /* optional mail */
        }

        return response()->json([
            'data' => $this->formatTicketDetail($ticket->fresh(), $user),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function storeAction(Request $request, OrderTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $this->userCanAccessTicket($user, $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (UserScope::isSupplier($user) && ! UserScope::isAdmin($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:return_slip,manual_return,warehouse_adjustment,refund,other'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        TicketAction::create([
            'ticket_id' => $ticket->id,
            'action' => $validated['action'],
            'performed_by' => $user->id,
            'notes' => $validated['notes'] ?? null,
        ]);
        $ticket->update(['status' => 'in_progress']);

        $ticket->load([
            'order.suppliers.supplier:id,name,email',
            'requester:id,name,email',
            'assignee:id,name,email',
            'messages.user:id,name',
            'actions.performer:id,name',
        ]);

        return response()->json([
            'data' => $this->formatTicketDetail($ticket->fresh(), $user),
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:order_tickets,id'],
        ]);

        $deleted = 0;
        foreach ($validated['ids'] as $id) {
            $ticket = OrderTicket::query()->find($id);
            if ($ticket) {
                $ticket->delete();
                $deleted++;
            }
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicketDetail(OrderTicket $ticket, User $viewer): array
    {
        $order = $ticket->order;
        $viewerRole = $order ? $this->participantRoleForOrder($viewer, $order) : 'other';

        $orderPayload = null;
        if ($order) {
            $orderPayload = [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'suppliers' => $order->suppliers->map(static function ($os) {
                    $su = $os->supplier;

                    return [
                        'id' => (int) $os->supplier,
                        'name' => $su->name ?? '',
                        'email' => $su->email ?? '',
                    ];
                })->values()->all(),
            ];
        }

        return [
            'id' => $ticket->id,
            'type' => $ticket->type,
            'status' => $ticket->status,
            'notes' => $ticket->notes,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'order' => $orderPayload,
            'requester' => $ticket->requester ? [
                'id' => $ticket->requester->id,
                'name' => $ticket->requester->name,
                'email' => $ticket->requester->email,
            ] : null,
            'assignee' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->name,
                'email' => $ticket->assignee->email,
            ] : null,
            'viewer' => [
                'role' => $viewerRole,
            ],
            'messages' => $ticket->messages->map(function ($m) use ($order) {
                return [
                    'id' => $m->id,
                    'body' => $m->body,
                    'attachments' => is_array($m->attachments) ? $m->attachments : [],
                    'created_at' => $m->created_at,
                    'user' => $m->user ? [
                        'id' => $m->user->id,
                        'name' => $m->user->name,
                    ] : null,
                    'sender_role' => $order && $m->user
                        ? $this->participantRoleForOrder($m->user, $order)
                        : 'other',
                ];
            })->values()->all(),
            'actions' => $ticket->actions->map(static fn ($a) => [
                'id' => $a->id,
                'action' => $a->action,
                'notes' => $a->notes,
                'created_at' => $a->created_at,
                'performer' => $a->performer ? [
                    'id' => $a->performer->id,
                    'name' => $a->performer->name,
                ] : null,
            ])->values()->all(),
        ];
    }

    private function userCanAccessOrder(?User $user, Order $order): bool
    {
        if (! $user) {
            return false;
        }
        if (UserScope::isAdmin($user)) {
            return true;
        }
        $uid = UserScope::effectiveMerchantUserId($user);

        return (int) $order->user_id === $uid
            || $order->suppliers()->where('customer', $uid)->exists()
            || $order->suppliers()->where('supplier', $uid)->exists();
    }

    private function userCanAccessTicket(?User $user, OrderTicket $ticket): bool
    {
        if (! $user) {
            return false;
        }
        if (UserScope::isAdmin($user)) {
            return true;
        }
        $order = $ticket->relationLoaded('order') ? $ticket->order : $ticket->order()->first();
        if (! $order) {
            return false;
        }

        return $this->userCanAccessOrder($user, $order);
    }

    private function participantRoleForOrder(?User $user, Order $order): string
    {
        if (! $user) {
            return 'guest';
        }
        if (UserScope::isAdmin($user)) {
            return 'admin';
        }
        $uid = UserScope::effectiveMerchantUserId($user);
        if ((int) $order->user_id === $uid) {
            return 'merchant';
        }
        if ($order->suppliers()->where('customer', $uid)->exists()) {
            return 'merchant';
        }
        if ($order->suppliers()->where('supplier', (int) $user->id)->exists()) {
            return 'supplier';
        }

        return 'other';
    }
}
