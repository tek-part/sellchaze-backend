<?php

namespace App\Services;

use App\Models\MarketplaceService;
use App\Models\MarketplaceServiceProvider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * G4 (#50): freelancer analytics for the service marketplace. Each viewer counts
 * once per target and UTC day; viewers are HMACs and providers never count themselves.
 */
class MarketplaceAnalytics
{
    public const CHANNELS = ['email', 'phone', 'whatsapp', 'telegram'];

    /** Never fails the page: a recording error is reported and the request continues. */
    public function record(Request $request, MarketplaceServiceProvider $provider, User $viewer, string $kind, ?MarketplaceService $service = null, ?string $channel = null): void
    {
        if ((int) $provider->user_id === (int) $viewer->id || $request->header('Sec-GPC') === '1' || $request->header('DNT') === '1') {
            return;
        }
        try {
            $now = now()->utc();
            DB::table('marketplace_engagements')->insertOrIgnore([
                'provider_id' => $provider->id, 'service_id' => $service?->id, 'kind' => $kind,
                'target' => $kind === 'contact_click' ? $channel.':'.($service?->id ?? '') : (string) $service?->id,
                'viewer_hash' => hash_hmac('sha256', 'marketplace-viewer:'.$viewer->id, (string) config('app.key')),
                'day' => $now->toDateString(), 'slot' => intdiv($now->hour * 60 + $now->minute, 15), 'occurred_at' => $now,
            ]);
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** Inclusive local dates in the viewer's timezone, aggregated in SQL over the provider's rows in that range. */
    public function report(MarketplaceServiceProvider $provider, string $from, string $to, string $zone): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $from, $zone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to, $zone)->addDay()->startOfDay();
        $rows = fn () => DB::table('marketplace_engagements')->where('provider_id', $provider->id)
            ->where('occurred_at', '>=', $start->copy()->utc())->where('occurred_at', '<', $end->copy()->utc());
        $daily = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'service_views' => 0, 'profile_views' => 0, 'contact_clicks' => 0];
        }
        $column = ['service_view' => 'service_views', 'profile_view' => 'profile_views', 'contact_click' => 'contact_clicks'];
        // One row per UTC quarter hour and kind, placed on the viewer's local day.
        foreach ($rows()->groupBy('day', 'slot', 'kind')->selectRaw('day, slot, kind, COUNT(*) as total')->get() as $row) {
            $local = Carbon::parse(substr((string) $row->day, 0, 10), 'UTC')->addMinutes((int) $row->slot * 15)->setTimezone($zone)->toDateString();
            if (isset($daily[$local], $column[$row->kind])) {
                $daily[$local][$column[$row->kind]] += (int) $row->total;
            }
        }
        $channels = array_fill_keys(self::CHANNELS, 0);
        foreach ($rows()->where('kind', 'contact_click')->groupBy('target')->selectRaw('target, COUNT(*) as total')->get() as $row) {
            $channel = explode(':', (string) $row->target)[0];
            if (isset($channels[$channel])) {
                $channels[$channel] += (int) $row->total;
            }
        }
        $services = $rows()->whereNotNull('service_id')->groupBy('service_id')
            ->selectRaw("service_id, SUM(CASE WHEN kind='service_view' THEN 1 ELSE 0 END) as views, SUM(CASE WHEN kind='contact_click' THEN 1 ELSE 0 END) as contact_clicks")
            ->get()->keyBy('service_id');
        $titles = MarketplaceService::withTrashed()->where('provider_id', $provider->id)->whereIn('id', $services->keys())->pluck('title', 'id');
        $daily = array_values($daily);

        return ['period' => ['from' => $from, 'to' => $to, 'timezone' => $zone],
            'summary' => ['service_views' => array_sum(array_column($daily, 'service_views')), 'profile_views' => array_sum(array_column($daily, 'profile_views')),
                'contact_clicks' => array_sum(array_column($daily, 'contact_clicks')), 'unique_visitors' => (int) $rows()->distinct()->count('viewer_hash')],
            'contact_channels' => $channels, 'daily' => $daily,
            'services' => $services->filter(fn ($row) => $titles->has($row->service_id))
                ->map(fn ($row) => ['id' => $row->service_id, 'title' => $titles->get($row->service_id), 'views' => (int) $row->views, 'contact_clicks' => (int) $row->contact_clicks])
                ->sortByDesc('views')->values()->all()];
    }
}
