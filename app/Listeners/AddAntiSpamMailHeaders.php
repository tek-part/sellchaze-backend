<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Inject anti-spam headers into outgoing email messages.
 *
 * Uses ID header helper for Message-ID compatibility with Symfony MIME.
 */
class AddAntiSpamMailHeaders
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;

        try {
            $headers = $message->getHeaders();

            $domain = 'sellchaze.com';
            $supportMail = 'info@' . $domain;
            $unsubMail = 'unsubscribe@' . $domain;
            $unsubUrl = 'https://' . $domain . '/unsubscribe';

            if (! $headers->has('List-Unsubscribe')) {
                $headers->addTextHeader('List-Unsubscribe', '<mailto:' . $unsubMail . '>, <' . $unsubUrl . '>');
            }
            if (! $headers->has('List-Unsubscribe-Post')) {
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }

            if (! $headers->has('Reply-To')) {
                $headers->addMailboxListHeader('Reply-To', [new Address($supportMail)]);
            }

            if (! $headers->has('Message-ID') && ! $headers->has('Message-Id')) {
                $messageId = sprintf('%s.%s@%s', bin2hex(random_bytes(8)), time(), $domain);
                if (method_exists($headers, 'addIdHeader')) {
                    $headers->addIdHeader('Message-ID', $messageId);
                } else {
                    $headers->addTextHeader('Message-ID', '<' . $messageId . '>');
                }
            }

            if (! $headers->has('X-Entity-Ref-ID')) {
                $headers->addTextHeader('X-Entity-Ref-ID', bin2hex(random_bytes(12)));
            }

            if (! $headers->has('X-Mailer')) {
                $headers->addTextHeader('X-Mailer', 'Sellchase-Platform/1.0');
            }

            if (! $headers->has('Auto-Submitted')) {
                $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            }

            if (! $headers->has('Precedence')) {
                $headers->addTextHeader('Precedence', 'bulk');
            }

            if (! $headers->has('MIME-Version')) {
                $headers->addTextHeader('MIME-Version', '1.0');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
