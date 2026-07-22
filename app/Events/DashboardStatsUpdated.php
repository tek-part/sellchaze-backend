<?php

namespace App\Events;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DashboardStatsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $ordersChartData;

    public array $ordersByStatus;

    public function __construct()
    {
        $ordersChartData = Order::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'orders' => (int) $row->count])
            ->toArray();

        $filledChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->format('Y-m-d');
            $found = collect($ordersChartData)->firstWhere('date', $d);
            $filledChartData[] = ['date' => $d, 'orders' => $found ? $found['orders'] : 0];
        }

        $ordersByStatus = Order::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status ?? 'pending', 'count' => (int) $row->count])
            ->toArray();

        $this->ordersChartData = $filledChartData;
        $this->ordersByStatus = $ordersByStatus;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('dashboard-stats');
    }

    public function broadcastAs(): string
    {
        return 'DashboardStatsUpdated';
    }
}
