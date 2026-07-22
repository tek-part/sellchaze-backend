<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

class RecordMailLog
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $tos = [];
        foreach ($message->getTo() as $addr) {
            $tos[] = $addr->getAddress();
        }
        $to = implode(', ', $tos);
        if ($to === '') {
            return;
        }

        $subject = $message->getSubject() ?? '';
        $templateKey = null;
        $headers = $message->getHeaders();
        if ($headers->has('X-Mail-Template-Key')) {
            $h = $headers->get('X-Mail-Template-Key');
            $templateKey = $h ? $h->getBodyAsString() : null;
        }

        EmailLog::query()->create([
            'to' => mb_substr($to, 0, 2000),
            'subject' => mb_substr($subject, 0, 1000),
            'template_key' => $templateKey !== null && $templateKey !== '' ? mb_substr($templateKey, 0, 128) : null,
            'status' => 'sent',
            'error_message' => null,
            'created_at' => now(),
        ]);
    }
}
