<?php

namespace App\Services\Commerce;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class MerchantPushTransport
{
    /** Return only a status; vendor response text and endpoint credentials never enter logs. */
    public function send(array $subscription, array $payload, string $topic): int
    {
        $sender = new WebPush(['VAPID' => app(MerchantPushSettings::class)->auth()], ['TTL' => 60, 'urgency' => 'high', 'contentType' => 'application/json'], 10, ['connect_timeout' => 5, 'allow_redirects' => false, 'verify' => true]);
        $report = $sender->sendOneNotification(Subscription::create($subscription), json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ['topic' => $topic]);

        return $report->getResponse()?->getStatusCode() ?? 0;
    }
}
