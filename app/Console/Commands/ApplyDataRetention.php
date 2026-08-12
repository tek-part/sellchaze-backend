<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyDataRetention extends Command
{
    protected $signature = 'operations:retention {--pretend : Count eligible records without deleting them}';

    protected $description = 'Apply configured retention windows to operational and audit data';

    public function handle(): int
    {
        $days = (int) config('operations.retention.audit_logs_days', 365);
        $targets = [
            'published_outbox' => OutboxMessage::query()->whereNotNull('published_at')->where('published_at', '<', now()->subDays((int) config('operations.retention.published_outbox_days', 30))),
            'activity_logs' => DB::table('activity_logs')->where('created_at', '<', now()->subDays($days)),
            'procurement_audit' => DB::table('procurement_audit_entries')->where('created_at', '<', now()->subDays($days)),
            'moderation_audit' => DB::table('moderation_actions')->where('created_at', '<', now()->subDays($days)),
        ];
        foreach ($targets as $name => $query) {
            $count = (clone $query)->count();
            if (! $this->option('pretend') && $count > 0) {
                $query->delete();
            }
            $this->line("{$name}: {$count}".($this->option('pretend') ? ' eligible' : ' deleted'));
        }

        return self::SUCCESS;
    }
}
