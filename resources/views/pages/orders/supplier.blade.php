<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center flex-wrap gap-3">
                            <div class="col">
                                <h2 class="fs-4 fw-bold mb-1">{{ __('Orders for supplier') }}: {{ $supplier->name }}</h2>
                                <p class="text-muted mb-0 fs-6">{{ __('Search and export this list.') }}</p>
                            </div>
                            <div class="col-auto">
                                <a href="{{ route('suppliers.payments') }}" class="btn btn-sm btn-primary"><i class="iconoir-arrow-left me-1"></i> {{ __('Back to supplier payments') }}</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($orders->isEmpty())
                            <div class="text-center py-5">
                                <i class="iconoir-delivery-truck fs-1 text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-0">{{ __('No orders for this supplier.') }}</p>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0 crm-datatable" data-export-name="orders-supplier-{{ $supplier->id }}">
                                    <thead class="table-light">
                                        <tr class="text-start fw-semibold fs-7 text-uppercase">
                                            <th class="ps-4 py-3 rounded-start">{{ __('Code') }}</th>
                                            <th class="py-3">{{ __('Product') }}</th>
                                            <th class="py-3">{{ __('Customer') }}</th>
                                            <th class="py-3">{{ __('Status') }}</th>
                                            <th class="py-3">{{ __('Created') }}</th>
                                            <th class="py-3 pe-4 rounded-end text-end no-export no-sort">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($orders as $order)
                                            <tr>
                                                <td class="ps-4 py-3"><span class="fw-semibold text-body">{{ $order->code }}</span></td>
                                                <td class="py-3">{{ optional($order->product)->name ?? '—' }}</td>
                                                <td class="py-3">{{ $order->customer_name ?? optional($order->user)->name ?? '—' }}</td>
                                                <td class="py-3">
                                                    @php
                                                        $statusClass = match(strtolower($order->status ?? '')) {
                                                            'pending' => 'warning',
                                                            'accepted', 'deal', 'completed' => 'success',
                                                            'rejected', 'cancelled' => 'danger',
                                                            default => 'secondary'
                                                        };
                                                    @endphp
                                                    <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($order->status ?? '') }}</span>
                                                </td>
                                                <td class="py-3 text-muted fs-7">{{ $order->created_at?->format('d M, Y H:i') }}</td>
                                                <td class="py-3 pe-4 text-end">
                                                    <a href="{{ route('orders.show', $order->code) }}" class="btn btn-sm btn-primary" title="{{ __('View') }}"><i class="las la-eye me-1"></i> {{ __('View') }}</a>
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
        </div>
    </x-crm.listing-wrap>
</x-default-layout>
