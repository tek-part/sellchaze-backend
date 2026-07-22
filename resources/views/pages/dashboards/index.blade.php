<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-js')
    <script src="{{ asset('rizz/libs/apexcharts/apexcharts.min.js') }}"></script>
    @endpush

    <div class="row">
        {{-- Left column: 2 KPI cards with sparklines (Rizz style) --}}
        <div class="col-md-12 col-lg-12 col-xl-4">
            <div class="row">
                <div class="col-md-12 col-lg-6 col-xl-12">
                    <a href="{{ route('orders.out') }}" class="text-decoration-none text-body">
                    <div class="card h-100">
                        <div class="card-body border-dashed-bottom pb-3">
                            <div class="row d-flex justify-content-between">
                                <div class="col-auto">
                                    <div class="d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle">
                                        <i class="icofont-money-bag h1 align-self-center mb-0 text-secondary"></i>
                                    </div>
                                    <h5 class="mt-2 mb-0 fs-14">{{ __('Orders Out') }}</h5>
                                </div>
                                <div class="col align-self-center">
                                    <div id="sparkline-orders-out" class="apex-charts float-end"></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row d-flex justify-content-center">
                                <div class="col-12 col-md-6">
                                    <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ formatNumber($ordersOutCount) }}</h2>
                                    <p class="mb-0 text-truncate text-muted">{{ __('View outgoing orders') }}</p>
                                </div>
                                <div class="col-12 col-md-6 align-self-center text-start text-md-end">
                                    <span class="btn btn-primary btn-sm px-2 mt-2 mt-md-0">{{ __('View Report') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </a>
                </div>
                <div class="col-md-12 col-lg-6 col-xl-12">
                    <a href="{{ route('orders.in') }}" class="text-decoration-none text-body">
                    <div class="card h-100">
                        <div class="card-body border-dashed-bottom pb-3">
                            <div class="row d-flex justify-content-between">
                                <div class="col-auto">
                                    <div class="d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle">
                                        <i class="icofont-opencart h1 align-self-center mb-0 text-secondary"></i>
                                    </div>
                                    <h5 class="mt-2 mb-0 fs-14">{{ __('Orders In') }}</h5>
                                </div>
                                <div class="col align-self-center">
                                    <div id="sparkline-orders-in" class="apex-charts float-end"></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row d-flex justify-content-center">
                                <div class="col-12 col-md-6">
                                    <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ formatNumber($ordersInCount) }}</h2>
                                    <p class="mb-0 text-truncate text-muted">{{ __('View incoming orders') }}</p>
                                </div>
                                <div class="col-12 col-md-6 align-self-center text-start text-md-end">
                                    <span class="btn btn-outline-secondary btn-sm px-2 mt-2 mt-md-0">{{ __('View Report') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </a>
                </div>
            </div>
        </div>

        {{-- Right column: Main chart + 4 mini cards --}}
        <div class="col-md-12 col-lg-12 col-xl-8">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Orders') }} ({{ __('last 7 days') }})</h4>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('orders.out') }}" class="btn btn-light btn-sm">
                                <i class="icofont-calendar fs-5 me-1"></i> {{ __('View all') }} <i class="las la-angle-down ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div id="monthly_income" class="apex-charts" style="min-height: 270px;"></div>
                    <div class="row">
                        <div class="col-md-6 col-lg-3">
                            <a href="{{ route('orders.out') }}" class="text-decoration-none text-body">
                            <div class="card shadow-none border mb-3 mb-lg-0">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col text-center">
                                            <span class="fs-18 fw-semibold">{{ formatNumber($ordersOutCount) }}</span>
                                            <h6 class="text-uppercase text-muted mt-2 m-0">{{ __('Orders Out') }}</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="{{ route('orders.in') }}" class="text-decoration-none text-body">
                            <div class="card shadow-none border mb-3 mb-lg-0">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col text-center">
                                            <span class="fs-18 fw-semibold">{{ formatNumber($ordersInCount) }}</span>
                                            <h6 class="text-uppercase text-muted mt-2 m-0">{{ __('Orders In') }}</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="{{ route('quotations.out') }}" class="text-decoration-none text-body">
                            <div class="card shadow-none border mb-3 mb-lg-0">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col text-center">
                                            <span class="fs-18 fw-semibold">{{ formatNumber($quotationsOutCount + $quotationsInCount) }}</span>
                                            <h6 class="text-uppercase text-muted mt-2 m-0">{{ __('Quotations') }}</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <a href="{{ route('deals.out') }}" class="text-decoration-none text-body">
                            <div class="card shadow-none border mb-3 mb-lg-0">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col text-center">
                                            <span class="fs-18 fw-semibold">{{ formatNumber($dealsOutCount + $dealsInCount) }}</span>
                                            <h6 class="text-uppercase text-muted mt-2 m-0">{{ __('Deals') }}</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        {{-- Recent orders table (Popular Products style) --}}
        <div class="col-md-6 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Recent orders') }}</h4>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('orders.out') }}" class="btn btn-light btn-sm">{{ __('View all') }}</a>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if($recentOrders->isEmpty())
                        <p class="text-muted py-4 mb-0">{{ __('No orders yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="border-top-0">{{ __('Order Code') }}</th>
                                        <th class="border-top-0">{{ __('Product') }}</th>
                                        <th class="border-top-0">{{ __('Qty') }}</th>
                                        <th class="border-top-0">{{ __('Status') }}</th>
                                        <th class="border-top-0">{{ __('Created') }}</th>
                                        <th class="border-top-0">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-grow-1 text-truncate">
                                                    <h6 class="m-0">{{ $order->code ?? '—' }}</h6>
                                                    <a href="{{ route('orders.show', $order->code) }}" class="fs-12 text-primary">{{ $order->ref_number ?? '—' }}</a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $order->product->name ?? '—' }}</td>
                                        <td>{{ formatNumber($order->quantity ?? 0) }}</td>
                                        <td>
                                            @if(($order->status ?? '') === 'accepted')
                                                <span class="badge bg-success-subtle text-success px-2">{{ $order->status }}</span>
                                            @else
                                                <span class="badge bg-primary-subtle text-primary px-2">{{ $order->status ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $order->created_at?->format('M d, Y') ?? '—' }}</td>
                                        <td>
                                            <a href="{{ route('orders.show', $order->code) }}" class="btn btn-sm btn-light">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Orders by status (Customers donut style) --}}
        <div class="col-md-6 col-lg-4">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Orders by status') }}</h4>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div id="customers" class="apex-charts" style="min-height: 280px;"></div>
                    <div class="text-center mt-3">
                        <a href="{{ route('orders.out') }}" class="btn btn-primary btn-sm">{{ __('More Detail') }} <i class="las la-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($usersCount !== null)
    <div class="row g-3 mt-1">
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('users.index') }}" class="text-decoration-none text-body">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle">
                            <i class="iconoir-user h1 align-self-center mb-0 text-secondary"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ formatNumber($usersCount) }}</h2>
                            <h6 class="text-uppercase text-muted mt-0 mb-0">{{ __('Users') }}</h6>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('products.index') }}" class="text-decoration-none text-body">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle">
                            <i class="iconoir-view-grid h1 align-self-center mb-0 text-secondary"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ formatNumber($productsCount) }}</h2>
                            <h6 class="text-uppercase text-muted mt-0 mb-0">{{ __('Products') }}</h6>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('categories.index') }}" class="text-decoration-none text-body">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 d-flex justify-content-center align-items-center thumb-xl border border-secondary rounded-circle">
                            <i class="las la-tags h1 align-self-center mb-0 text-secondary"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="fs-22 mt-0 mb-1 fw-bold">{{ formatNumber($categoriesCount) }}</h2>
                            <h6 class="text-uppercase text-muted mt-0 mb-0">{{ __('Categories') }}</h6>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
    </div>
    @endif

    <x-slot name="script">
    <script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
    <script>
    (function() {
        var ordersChartData = {!! json_encode($ordersChartData ?? []) !!};
        var ordersByStatus = {!! json_encode($ordersByStatus ?? []) !!};
        var sparklineData = ordersChartData.length ? ordersChartData.map(function(d) { return d.orders; }) : [25, 66, 41, 89, 63, 25, 44];

        var lineChart = null;
        var donutChart = null;
        var sparkOut = null;
        var sparkIn = null;
        var piePalette = ["#22c55e", "#08b0e7", "#ffc728", "#95a0c5", "#6366f1", "#ec4899"];

        var sparkOptions = {
            series: [{ data: sparklineData }],
            chart: { type: "line", width: 120, height: 35, sparkline: { enabled: true }, dropShadow: { enabled: true, top: 4, left: 0, bottom: 0, right: 0, blur: 2, color: "rgba(132, 145, 183, 0.3)", opacity: 0.35 } },
            colors: ["#95a0c5"],
            stroke: { show: true, curve: "smooth", width: [3], lineCap: "round" },
            tooltip: { fixed: { enabled: false }, x: { show: false }, y: { title: { formatter: function() { return ""; } } }, marker: { show: false } }
        };

        function initCharts() {
            if (typeof ApexCharts === "undefined") return;

            var lineEl = document.getElementById("monthly_income");
            if (lineEl) {
                try {
                    var lineCategories = ordersChartData.length ? ordersChartData.map(function(d) { return d.date; }) : ["—"];
                    var lineSeries = ordersChartData.length ? ordersChartData.map(function(d) { return d.orders; }) : [0];
                    var lineOpts = {
                        series: [{ name: "{{ __('Orders') }}", data: lineSeries }],
                        chart: { type: "area", height: 270, toolbar: { show: false }, dropShadow: { enabled: true, top: 0, left: 5, bottom: 5, right: 0, blur: 5, color: "#45404a2e", opacity: 0.35 } },
                        colors: ["#22c55e"],
                        stroke: { curve: "smooth", width: 3 },
                        fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
                        xaxis: { categories: lineCategories },
                        dataLabels: { enabled: false },
                        grid: { borderColor: "#e9ecef", strokeDashArray: 2.5 },
                        tooltip: { y: { formatter: function(v) { return v; } } }
                    };
                    lineChart = new ApexCharts(lineEl, lineOpts);
                    lineChart.render();
                } catch (e) { console.warn("Line chart error:", e); }
            }

            var el1 = document.querySelector("#sparkline-orders-out");
            if (el1) {
                sparkOut = new ApexCharts(el1, sparkOptions);
                sparkOut.render();
            }
            var el2 = document.querySelector("#sparkline-orders-in");
            if (el2) {
                sparkIn = new ApexCharts(el2, sparkOptions);
                sparkIn.render();
            }

            var pieEl = document.getElementById("customers");
            if (pieEl) {
                try {
                    var donutSeries = ordersByStatus.length ? ordersByStatus.map(function(d) { return d.count; }) : [0];
                    var donutLabels = ordersByStatus.length ? ordersByStatus.map(function(d) { return d.status; }) : ["{{ __('No data') }}"];
                    if (donutSeries.every(function(x) { return x === 0; })) {
                        donutSeries = [1];
                        donutLabels = ["{{ __('No data') }}"];
                    }
                    var donutOpts = {
                        series: donutSeries,
                        chart: { height: 280, type: "donut" },
                        plotOptions: { pie: { donut: { size: "80%" } } },
                        dataLabels: { enabled: false },
                        stroke: { show: true, width: 2, colors: ["transparent"] },
                        colors: piePalette.slice(0, Math.max(donutSeries.length, 1)),
                        labels: donutLabels,
                        legend: { show: true, position: "bottom", horizontalAlign: "center" },
                        tooltip: { y: { formatter: function(o) { return o + " {{ __('orders') }}"; } } }
                    };
                    donutChart = new ApexCharts(pieEl, donutOpts);
                    donutChart.render();
                } catch (e) { console.warn("Donut chart error:", e); }
            }
        }

        function updateChartsFromBroadcast(data) {
            if (data.ordersChartData && data.ordersChartData.length && lineChart) {
                lineChart.updateOptions({ xaxis: { categories: data.ordersChartData.map(function(d) { return d.date; }) } });
                lineChart.updateSeries([{ name: "{{ __('Orders') }}", data: data.ordersChartData.map(function(d) { return d.orders; }) }]);
            }
            if (data.ordersByStatus && data.ordersByStatus.length && donutChart) {
                donutChart.updateOptions({
                    series: data.ordersByStatus.map(function(d) { return d.count; }),
                    labels: data.ordersByStatus.map(function(d) { return d.status; })
                });
            }
        }

        if (typeof ApexCharts !== "undefined") {
            document.addEventListener("DOMContentLoaded", initCharts);
        } else {
            window.addEventListener("load", function() { setTimeout(initCharts, 200); });
        }

        var pusherKey = {!! json_encode(config('broadcasting.connections.pusher.key')) !!};
        var pusherCluster = {!! json_encode(config('broadcasting.connections.pusher.options.cluster') ?? 'mt1') !!};
        if (pusherKey && typeof Pusher !== "undefined") {
            try {
                var pusher = new Pusher(pusherKey, { cluster: pusherCluster, forceTLS: true });
                var channel = pusher.subscribe("dashboard-stats");
                channel.bind("DashboardStatsUpdated", updateChartsFromBroadcast);
            } catch (e) { console.warn("Pusher init error:", e); }
        }
    })();
    </script>
    </x-slot>
</x-default-layout>
