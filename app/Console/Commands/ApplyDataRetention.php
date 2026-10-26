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
        $sessions = now()->subDays(max(1, (int) config('operations.retention.storefront_sessions_days', 30)));
        $targets = [
            'published_outbox' => OutboxMessage::query()->whereNotNull('published_at')->where('published_at', '<', now()->subDays((int) config('operations.retention.published_outbox_days', 30))),
            'activity_logs' => DB::table('activity_logs')->where('created_at', '<', now()->subDays($days)),
            'moderation_audit' => DB::table('moderation_actions')->where('created_at', '<', now()->subDays($days)),
            'storefront_session_campaigns' => DB::table('storefront_session_campaigns')->where('updated_at', '<', $sessions)
                ->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('store_orders')->whereColumn('store_orders.store_id', 'storefront_session_campaigns.store_id')
                    ->whereColumn('store_orders.analytics_session_hash', 'storefront_session_campaigns.session_key')),
            'storefront_session_products' => DB::table('storefront_session_products')->where('created_at', '<', $sessions),
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
