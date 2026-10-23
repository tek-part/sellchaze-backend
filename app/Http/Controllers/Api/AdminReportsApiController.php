<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CurrencyRateService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReportsApiController extends Controller
{
    private function range(Request $r): array
    {
        $days = max(1, min(365, (int) $r->query('range', 30)));

        return [$days, Carbon::now()->subDays($days)->startOfDay()];
    }

    /** Store orders across every store (admin reports, so no tenant scope). */
    private function storeOrders(): Builder
    {
        return DB::table('store_orders')->whereNull('deleted_at');
    }

    private function fillSeries(Builder $query, Carbon $since, int $days): array
    {
        $raw = $query->where('created_at', '>=', $since)->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')->pluck('c', 'd')->all();

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $out[] = ['date' => $d, 'count' => (int) ($raw[$d] ?? 0)];
        }

        return $out;
    }

    public function users(Request $r): JsonResponse
    {
        [$days, $since] = $this->range($r);

        $timeseries = $this->fillSeries(User::query()->toBase(), $since, $days);

        $breakdown = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('roles.name', DB::raw('COUNT(*) as c'))
            ->groupBy('roles.name')->get()
            ->map(fn ($r) => ['label' => $r->name, 'count' => (int) $r->c]);

        return response()->json([
            'totals' => [
                'total' => User::count(),
                'new_in_range' => User::where('created_at', '>=', $since)->count(),
                'pending' => User::where('pending_approval', true)->count(),
            ],
            'timeseries' => $timeseries,
            'breakdown' => $breakdown,
        ]);
    }

    public function orders(Request $r): JsonResponse
    {
        [$days, $since] = $this->range($r);

        $timeseries = $this->fillSeries($this->storeOrders(), $since, $days);

        $breakdown = $this->storeOrders()->where('created_at', '>=', $since)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->get()
            ->map(fn ($r) => ['label' => $r->status ?? 'unknown', 'count' => (int) $r->c]);

        return response()->json([
            'totals' => [
                'total' => $this->storeOrders()->count(),
                'in_range' => $this->storeOrders()->where('created_at', '>=', $since)->count(),
            ],
            'timeseries' => $timeseries,
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * Paid store-order revenue converted to USD at the stored rates. The `accepted_*` keys keep the
     * response shape the dashboard already reads.
     */
    public function revenue(Request $r, CurrencyRateService $rates): JsonResponse
    {
        [$days, $since] = $this->range($r);

        $paid = fn () => $this->storeOrders()->where('payment_status', 'paid');
        $toUsd = fn ($amount, ?string $currency) => (float) ($rates->convertToUsd((float) $amount, $currency) ?? 0);

        $daily = [];
        $paid()->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as d'), 'currency', DB::raw('SUM(grand_total) as s'))
            ->groupBy('d', 'currency')->get()
            ->each(function ($row) use (&$daily, $toUsd) {
                $daily[$row->d] = ($daily[$row->d] ?? 0) + $toUsd($row->s, $row->currency);
            });

        $timeseries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $timeseries[] = ['date' => $d, 'amount' => round((float) ($daily[$d] ?? 0), 2)];
        }

        $sum = fn ($query) => $query->select('currency', DB::raw('SUM(grand_total) as s'))->groupBy('currency')->get()
            ->sum(fn ($row) => $toUsd($row->s, $row->currency));
        $breakdown = $paid()->where('created_at', '>=', $since)
            ->select('currency', DB::raw('COUNT(*) as c'))->groupBy('currency')->get()
            ->map(fn ($row) => ['label' => strtoupper((string) $row->currency), 'count' => (int) $row->c]);

        return response()->json([
            'totals' => [
                'accepted_in_range_usd' => round($sum($paid()->where('created_at', '>=', $since)), 2),
                'accepted_all_time_usd' => round($sum($paid()), 2),
                'accepted_count_in_range' => $paid()->where('created_at', '>=', $since)->count(),
            ],
            'timeseries' => $timeseries,
            'breakdown' => $breakdown,
        ]);
    }
}
