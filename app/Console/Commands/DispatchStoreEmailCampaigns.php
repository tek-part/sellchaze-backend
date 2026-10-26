<?php

namespace App\Console\Commands;

use App\Services\Marketing\EmailCampaignSender;
use Illuminate\Console\Command;

class DispatchStoreEmailCampaigns extends Command
{
    protected $signature = 'email-campaigns:dispatch';

    protected $description = 'Start due e-mail campaigns and queue their next throttled batches';

    public function handle(EmailCampaignSender $sender): int
    {
        $result = $sender->run();
        $this->info("Started {$result['started']} campaign(s), queued {$result['dispatched']} recipient(s), completed {$result['completed']} campaign(s).");

        return self::SUCCESS;
    }
}
