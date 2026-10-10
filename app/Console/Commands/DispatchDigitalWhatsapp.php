<?php

namespace App\Console\Commands;

use App\Jobs\SendDigitalWhatsapp;
use App\Models\OutboxMessage;
use App\Services\Commerce\DigitalDeliverySettings;
use Illuminate\Console\Command;

class DispatchDigitalWhatsapp extends Command
{
    protected $signature = 'digital-delivery:dispatch-whatsapp {--limit=100}';

    protected $description = 'Recover published digital WhatsApp events whose transport has not been claimed';

    public function handle(): int
    {
        $messages = OutboxMessage::query()->where('event_type', DigitalDeliverySettings::CHANNELS['whatsapp'])
            ->whereNotNull('published_at')->whereNull('metadata->transport_state')
            ->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        foreach ($messages as $message) {
            SendDigitalWhatsapp::dispatch($message->id);
        }
        $this->info('Queued '.$messages->count().' unclaimed digital WhatsApp events.');

        return self::SUCCESS;
    }
}
