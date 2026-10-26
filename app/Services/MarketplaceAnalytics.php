<?php

namespace App\Services;

use App\Models\MarketplaceService;
use App\Models\MarketplaceServiceProvider;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * G4 (#50): freelancer analytics for the service marketplace. Each viewer counts
 * once per target and UTC day; viewers are HMACs and providers never count themselves.
 */
class MarketplaceAnalytics
{
    public const CHANNELS = ['email', 'phone', 'whatsapp', 'telegram'];

    public function record(MarketplaceServiceProvider $provider, User $viewer, string $kind, ?MarketplaceService $service = null, ?string $channel = null): void
    {
        if ((int) $provider->user_id === (int) $viewer->id) {
            return;
        }
        $now = now()->utc();
        DB::table('marketplace_engagements')->insertOrIgnore([
            'provider_id' => $provider->id, 'service_id' => $service?->id, 'kind' => $kind,
            'target' => $kind === 'contact_click' ? (string) $channel : (string) $service?->id,
            'viewer_hash' => hash_hmac('sha256', 'marketplace-viewer:'.$viewer->id, (string) config('app.key')),
            'day' => $now->toDateString(), 'occurred_at' => $now,
        ]);
    }

    /** Inclusive local dates in the viewer's timezone; rows are bucketed by their UTC timestamp converted to that zone. */
    public function report(MarketplaceServiceProvider $provider, string $from, string $to, string $zone): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from, $zone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to, $zone)->addDay()->startOfDay();
        $daily = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'service_views' => 0, 'profile_views' => 0, 'contact_clicks' => 0];
        }
        $column = ['service_view' => 'service_views', 'profile_view' => 'profile_views', 'contact_click' => 'contact_clicks'];
        $services = [];
        $viewers = [];
        $channels = array_fill_keys(self::CHANNELS, 0);
        DB::table('marketplace_engagements')->where('provider_id', $provider->id)
            ->where('occurred_at', '>=', $start->copy()->utc())->where('occurred_at', '<', $end->copy()->utc())
            ->select(['id', 'kind', 'service_id', 'target', 'viewer_hash', 'occurred_at'])
            ->lazyById(1000)->each(function ($row) use (&$daily, &$services, &$viewers, &$channels, $column, $zone) {
                $key = Carbon::parse($row->occurred_at, 'UTC')->setTimezone($zone)->toDateString();
                if (! isset($daily[$key], $column[$row->kind])) {
                    return;
                }
                $daily[$key][$column[$row->kind]]++;
                $viewers[$row->viewer_hash] = true;
                if ($row->kind === 'contact_click' && isset($channels[$row->target])) {
                    $channels[$row->target]++;
                }
                if ($row->service_id) {
                    $services[$row->service_id] ??= ['views' => 0, 'contact_clicks' => 0];
                    $services[$row->service_id][$row->kind === 'service_view' ? 'views' : 'contact_clicks']++;
                }
            });
        $titles = MarketplaceService::withTrashed()->where('provider_id', $provider->id)->whereIn('id', array_keys($services))->pluck('title', 'id');
        $daily = array_values($daily);

        return ['period' => ['from' => $from, 'to' => $to, 'timezone' => $zone],
            'summary' => ['service_views' => array_sum(array_column($daily, 'service_views')), 'profile_views' => array_sum(array_column($daily, 'profile_views')),
                'contact_clicks' => array_sum(array_column($daily, 'contact_clicks')), 'unique_visitors' => count($viewers)],
            'contact_channels' => $channels, 'daily' => $daily,
            'services' => collect($services)->map(fn ($counts, $id) => ['id' => $id, 'title' => $titles->get($id)] + $counts)
                ->filter(fn ($row) => $row['title'] !== null)->sortByDesc('views')->values()->all()];
    }
}
