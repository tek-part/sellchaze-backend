<?php

namespace App\Services\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketNotification;
use App\Services\Rbac\UserScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Opening, replying to and moving support tickets, plus the JSON shape both dashboards read. */
class SupportTickets
{
    public const DISK = 'local';

    /** @param  list<UploadedFile>  $files */
    public function open(User $actor, array $data, array $files = []): SupportTicket
    {
        $ownerId = UserScope::effectiveMerchantUserId($actor);

        $ticket = DB::transaction(function () use ($actor, $ownerId, $data, $files) {
            $ticket = SupportTicket::create([
                'owner_user_id' => $ownerId,
                'opened_by_user_id' => $actor->id,
                'store_id' => Store::query()->where('owner_user_id', $ownerId)->value('id'),
                'subject' => $data['subject'],
                'category' => $data['category'] ?? 'general',
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'last_reply_by' => 'merchant',
                'last_reply_at' => now(),
                'merchant_read_at' => now(),
            ]);
            $this->addMessage($ticket, $actor, false, $data['body'], $files);

            return $ticket;
        });

        $this->notifySupport($ticket, 'created');

        return $ticket;
    }

    /** @param  list<UploadedFile>  $files */
    public function reply(SupportTicket $ticket, User $actor, bool $asStaff, string $body, array $files = []): SupportTicketMessage
    {
        abort_if($ticket->isClosed(), 422, 'This ticket is closed. Open a new ticket instead.');

        $message = DB::transaction(function () use ($ticket, $actor, $asStaff, $body, $files) {
            $message = $this->addMessage($ticket, $actor, $asStaff, $body, $files);
            $ticket->fill($asStaff
                // The other side has not seen this reply yet.
                ? ['last_reply_by' => 'support', 'status' => 'awaiting_merchant', 'support_read_at' => now(), 'merchant_read_at' => null]
                : ['last_reply_by' => 'merchant', 'status' => 'open', 'merchant_read_at' => now(), 'support_read_at' => null, 'resolved_at' => null]);
            $ticket->last_reply_at = now();
            if ($asStaff && $ticket->assigned_to_user_id === null) {
                $ticket->assigned_to_user_id = $actor->id;
            }
            $ticket->save();

            return $message;
        });

        $asStaff ? $this->notifyMerchant($ticket, 'replied') : $this->notifySupport($ticket, 'replied');

        return $message;
    }

    public function changeStatus(SupportTicket $ticket, string $status, bool $byStaff): SupportTicket
    {
        if ($ticket->status === $status) {
            return $ticket;
        }
        $ticket->update([
            'status' => $status,
            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now() : null,
        ]);
        if ($byStaff && in_array($status, ['resolved', 'closed'], true)) {
            $this->notifyMerchant($ticket, $status);
        }

        return $ticket;
    }

    /** @param  list<UploadedFile>  $files */
    private function addMessage(SupportTicket $ticket, User $actor, bool $asStaff, string $body, array $files): SupportTicketMessage
    {
        $attachments = [];
        foreach ($files as $file) {
            $path = $file->storeAs('support/'.$ticket->id, Str::uuid().'.'.$file->extension(), self::DISK);
            $attachments[] = [
                'path' => $path,
                'name' => Str::limit($file->getClientOriginalName(), 120, ''),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $ticket->messages()->create([
            'user_id' => $actor->id,
            'is_staff' => $asStaff,
            'body' => $body,
            'attachments' => $attachments ?: null,
        ]);
    }

    private function notifySupport(SupportTicket $ticket, string $event): void
    {
        $recipients = User::permission('support-tickets-manage')->where('is_active', true)->get();
        if ($ticket->assigned_to_user_id && $event === 'replied') {
            $recipients = $recipients->where('id', $ticket->assigned_to_user_id);
        }
        $this->deliver(fn () => Notification::send($recipients, new SupportTicketNotification($ticket, $event, true)));
    }

    private function notifyMerchant(SupportTicket $ticket, string $event): void
    {
        $owner = $ticket->owner;
        if ($owner) {
            $this->deliver(fn () => $owner->notify(new SupportTicketNotification($ticket, $event, false)));
        }
    }

    /** The ticket and message are already saved; a mail outage must not turn that into an error. */
    private function deliver(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function attachmentPath(SupportTicketMessage $message, int $index): ?array
    {
        $file = ($message->attachments ?? [])[$index] ?? null;

        return $file && Storage::disk(self::DISK)->exists($file['path']) ? $file : null;
    }

    /** @return array<string, mixed> */
    public function summary(SupportTicket $ticket, bool $forStaff): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'last_reply_by' => $ticket->last_reply_by,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'unread' => $forStaff ? $ticket->unreadForSupport() : $ticket->unreadForMerchant(),
            'messages_count' => (int) ($ticket->messages_count ?? $ticket->messages()->count()),
            'store' => $ticket->store ? ['id' => $ticket->store->id, 'name' => $ticket->store->name, 'slug' => $ticket->store->slug] : null,
            'owner' => $forStaff && $ticket->owner ? ['id' => $ticket->owner->id, 'name' => $ticket->owner->name, 'email' => $ticket->owner->email] : null,
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SupportTicket $ticket, bool $forStaff): array
    {
        $ticket->loadMissing(['messages.author:id,name', 'store', 'owner', 'assignee']);
        $base = $forStaff ? '/admin/support/tickets/' : '/support/tickets/';

        return $this->summary($ticket, $forStaff) + [
            'messages' => $ticket->messages->sortBy('id')->values()->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'is_staff' => $m->is_staff,
                'author' => $m->author ? ['id' => $m->author->id, 'name' => $m->author->name] : null,
                'attachments' => collect($m->attachments ?? [])->values()->map(fn (array $file, int $i) => [
                    'name' => $file['name'],
                    'mime' => $file['mime'],
                    'size' => $file['size'],
                    'url' => $base.$ticket->id.'/messages/'.$m->id.'/attachments/'.$i,
                ])->all(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
