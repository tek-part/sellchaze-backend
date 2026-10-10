<?php

namespace App\Console\Commands;

use App\Models\ProductPersonalizationUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneProductPersonalization extends Command
{
    protected $signature = 'personalization:prune';

    protected $description = 'Remove expired customization uploads that have never been used in an order';

    public function handle(): int
    {
        $count = 0;
        ProductPersonalizationUpload::query()->whereNull('claimed_at')->where('expires_at', '<', now())->orderBy('id')->chunkById(100, function ($uploads) use (&$count) {
            foreach ($uploads as $candidate) {
                DB::transaction(function () use ($candidate, &$count) {
                    $upload = ProductPersonalizationUpload::query()->whereKey($candidate->id)->whereNull('claimed_at')->where('expires_at', '<', now())->lockForUpdate()->first();
                    if ($upload && Storage::disk('local')->delete($upload->path)) {
                        $upload->delete();
                        $count++;
                    }
                });
            }
        });
        $this->info('Removed '.$count.' expired unused uploads.');

        return self::SUCCESS;
    }
}
