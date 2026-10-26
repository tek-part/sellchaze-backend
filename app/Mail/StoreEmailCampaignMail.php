<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Services\Marketing\EmailCampaignContent;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * A marketing campaign e-mail in the store's branded layout. Sent to a snapshotted recipient (tracked
 * links, open pixel, personal unsubscribe link) or as a merchant test (direct links, "[TEST]" subject).
 * Every message carries List-Unsubscribe + List-Unsubscribe-Post (RFC 8058 one-click).
 */
class StoreEmailCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StoreEmailCampaign $campaign,
        public ?StoreEmailCampaignRecipient $recipient = null,
        public bool $test = false,
    ) {}

    public function build(): static
    {
        $store = Store::query()->findOrFail($this->campaign->store_id);
        $data = app(EmailCampaignContent::class)->render($this->campaign, $store, $this->test ? null : $this->recipient);
        $settings = app(StoreNotificationSettings::class)->configured($store);
        $subject = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', ($this->test ? '[TEST] ' : '').$this->campaign->subject)), 0, 200);
        $sender = trim((string) preg_replace('/[\x00-\x1f\x7f"<>]+/u', ' ', (string) ($this->campaign->from_name ?: ($settings['sender_name'] ?? null) ?: $store->name)));

        $mail = $this->from(config('mail.from.address'), mb_substr($sender, 0, 100))->subject($subject)
            ->view('emails.store-campaign', $data)
            ->text('emails.store-campaign-text', $data);
        $replyTo = collect([$this->campaign->reply_to, $settings['reply_to'] ?? null, $store->email])->first(fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL));
        if ($replyTo) {
            $mail->replyTo($replyTo, $store->name);
        }
        $unsubscribe = $data['unsubscribeUrl'];

        return $mail->withSymfonyMessage(function (Email $message) use ($unsubscribe) {
            $headers = $message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $headers->addTextHeader('Precedence', 'bulk');
            $headers->addTextHeader('X-Mail-Template-Key', 'store-campaign');
            if ($this->recipient && ! $this->test) {
                $headers->addIdHeader('Message-ID', 'campaign-'.$this->campaign->id.'-'.$this->recipient->id.'@sellchaze.com');
            }
        });
    }
}
