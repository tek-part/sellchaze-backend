<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\User;
use Carbon\Carbon;
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

    private function fillSeries(string $model, Carbon $since, int $days, ?string $where = null): array
    {
        $q = $model::where('created_at', '>=', $since);
        if ($where) {
            $q->whereRaw($where);
        }
        $raw = $q->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
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

        $timeseries = $this->fillSeries(User::class, $since, $days);

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

        $timeseries = $this->fillSeries(Order::class, $since, $days);

        $breakdown = Order::where('created_at', '>=', $since)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->get()
            ->map(fn ($r) => ['label' => $r->status ?? 'unknown', 'count' => (int) $r->c]);

        return response()->json([
            'totals' => [
                'total' => Order::count(),
                'in_range' => Order::where('created_at', '>=', $since)->count(),
            ],
            'timeseries' => $timeseries,
            'breakdown' => $breakdown,
        ]);
    }

    public function revenue(Request $r): JsonResponse
    {
        [$days, $since] = $this->range($r);

        $raw = OrderQuotations::where('status', 'accepted')
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(price) as s'))
            ->groupBy('d')->pluck('s', 'd')->all();

        $timeseries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $timeseries[] = ['date' => $d, 'amount' => round((float) ($raw[$d] ?? 0), 2)];
        }

        $totalAccepted = (float) OrderQuotations::where('status', 'accepted')
            ->where('created_at', '>=', $since)->sum('price');
        $totalAllTime = (float) OrderQuotations::where('status', 'accepted')->sum('price');

        return response()->json([
            'totals' => [
                'accepted_in_range_usd' => round($totalAccepted, 2),
                'accepted_all_time_usd' => round($totalAllTime, 2),
                'accepted_count_in_range' => OrderQuotations::where('status', 'accepted')
                    ->where('created_at', '>=', $since)->count(),
            ],
            'timeseries' => $timeseries,
            'breakdown' => [],
        ]);
    }

    public function tickets(Request $r): JsonResponse
    {
        [$days, $since] = $this->range($r);

        $table = 'order_tickets';

        $series = DB::table($table)
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')->pluck('c', 'd')->all();

        $timeseries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $timeseries[] = ['date' => $d, 'count' => (int) ($series[$d] ?? 0)];
        }

        $breakdown = DB::table($table)
            ->where('created_at', '>=', $since)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')->get()
            ->map(fn ($r) => ['label' => $r->status ?? 'unknown', 'count' => (int) $r->c]);

        // Avg resolution time (hours) where resolved/closed.
        $avgHours = DB::table($table)
            ->whereIn('status', ['resolved', 'closed'])
            ->where('created_at', '>=', $since)
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as h')
            ->value('h');

        return response()->json([
            'totals' => [
                'total_in_range' => DB::table($table)->where('created_at', '>=', $since)->count(),
                'resolved_in_range' => DB::table($table)
                    ->whereIn('status', ['resolved', 'closed'])
                    ->where('created_at', '>=', $since)->count(),
                'avg_resolution_hours' => $avgHours ? round((float) $avgHours, 1) : null,
            ],
            'timeseries' => $timeseries,
            'breakdown' => $breakdown,
        ]);
    }
}
