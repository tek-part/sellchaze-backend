<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
    </style>
    @endpush
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('My quotations in') }}</h4>
                            <p class="text-muted mb-0 small">{{ __('Orders you received quotations for — search and export below.') }}</p>
                        </div>
                        <div class="col-auto">
                            @if(!$orders->isEmpty())
                                <button type="button" class="btn btn-light border" id="crm-quotations-in-excel-btn" title="{{ __('Export Excel') }}">
                                    <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if($orders->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No quotations yet.') }}</p>
                    @else
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="quotations-in" id="kt_table_quotations_in" data-dt-hide-buttons-ui="1" data-dt-order='[[0,"desc"]]'>
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">{{ __('Order Code') }}</th>
                                    <th>{{ __('Ref. Num') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th class="text-center">{{ __('Qty') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-center">{{ __('Quotations') }}</th>
                                    <th>{{ __('Sent to') }}</th>
                                    <th class="no-export">{{ __('Image') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                <tr>
                                    <td class="ps-4"><a class="fw-medium text-primary" href="{{ route('orders.show', $order->code) }}" target="_blank">{{ $order->code }}</a></td>
                                    <td>{{ $order->ref_number ?? '—' }}</td>
                                    <td>
                                        @if($order->product)
                                            <a href="{{ route('products.show', $order->product->id) }}" target="_blank" class="text-body">{{ str($order->product->name)->limit(40) }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center"><span class="badge bg-primary-subtle text-primary">{{ formatNumber($order->quantity) }}</span></td>
                                    <td>
                                        @php $statusClass = match(strtolower($order->status ?? '')) { 'pending' => 'warning', 'accepted'=> 'success', 'deal'=> 'success', 'completed'=> 'success', 'rejected'=> 'danger', 'cancelled'=> 'danger', default => 'secondary' }; @endphp
                                        <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($order->status ?? '') }}</span>
                                    </td>
                                    <td class="text-center">{{ $order->quotations->count() }}</td>
                                    <td>
                                        @if($order->suppliers && $order->suppliers->isNotEmpty())
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($order->suppliers as $supplier)
                                                    @php $userInfo = getUserInfo($supplier->supplier); @endphp
                                                    @if($userInfo && $userInfo->profile)
                                                        <a href="{{ route('profile.show', $userInfo->profile->username) }}" target="_blank" class="fs-7">{{ $userInfo->name }}</a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="no-export">
                                        <a href="{{ $order->image ? asset('/storage/uploads/orders/original/'.$order->image) : order_image($order) }}" target="_blank">
                                            <img src="{{ order_image($order) }}" alt="" class="rounded" style="width:50px;height:50px;object-fit:cover;" onerror="this.onerror=null; this.src='{{ placeholder_image('order') }}';">
                                        </a>
                                    </td>
                                    <td class="fs-7 text-muted">{{ $order->created_at?->format('d M, Y') }}</td>
                                    <td class="text-end">
                                        @if($order->quotations->count() > 0)
                                            <a class="btn btn-sm btn-success" href="{{ route('orders.quotations', $order->code) }}">{{ __('Quotations') }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
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

    <x-slot name="script">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tableEl = document.getElementById('kt_table_quotations_in');
            var excelBtn = document.getElementById('crm-quotations-in-excel-btn');
            if (tableEl && excelBtn && typeof jQuery !== 'undefined') {
                function bindExcel() {
                    if (!jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(tableEl)) { setTimeout(bindExcel, 50); return; }
                    var api = jQuery(tableEl).DataTable();
                    excelBtn.addEventListener('click', function() {
                        try { api.button('.buttons-excel').trigger(); } catch (e) {
                            var $h = jQuery(tableEl).closest('.dataTables_wrapper').find('.buttons-excel').first();
                            if ($h.length) $h.trigger('click');
                        }
                    });
                }
                bindExcel();
            }
        });
    </script>
    </x-slot>
</x-default-layout>
