<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
        .orders-in-table-wrap th.actions-col, .orders-in-table-wrap td.actions-col { position: sticky; right: 0; background: var(--bs-body-bg, #fff); white-space: nowrap; box-shadow: -4px 0 8px rgba(0,0,0,.06); z-index: 1; }
        .orders-in-table-wrap thead th.actions-col { background: var(--bs-light, #f1faff); }
        .orders-in-table-wrap tbody tr:hover td.actions-col { background: var(--bs-body-bg, #fff); }
    </style>
    @endpush
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Orders In') }}</h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <form method="GET" action="{{ route('orders.in') }}" id="kt_orders_in_filter_form" class="d-flex flex-wrap align-items-center gap-2">
                                    <input type="text" name="search" class="form-control form-control-sm w-auto" style="min-width: 180px;" placeholder="{{ __('Search') }}" value="{{ ($filters ?? [])['search'] ?? '' }}" />
                                    <select name="status" class="form-select form-select-sm w-auto" style="min-width: 130px;">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="pending" {{ (($filters ?? [])['status'] ?? '') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                        <option value="accepted" {{ (($filters ?? [])['status'] ?? '') === 'accepted' ? 'selected' : '' }}>{{ __('Accepted') }}</option>
                                        <option value="completed" {{ (($filters ?? [])['status'] ?? '') === 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                                        <option value="rejected" {{ (($filters ?? [])['status'] ?? '') === 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                                        <option value="cancelled" {{ (($filters ?? [])['status'] ?? '') === 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
                                    </select>
                                    <input type="date" name="date_from" class="form-control form-control-sm w-auto" value="{{ ($filters ?? [])['date_from'] ?? '' }}" />
                                    <input type="date" name="date_to" class="form-control form-control-sm w-auto" value="{{ ($filters ?? [])['date_to'] ?? '' }}" />
                                    <button type="submit" class="btn btn-light btn-sm">{{ __('Apply') }}</button>
                                    <a href="{{ route('orders.in') }}" class="btn btn-light btn-sm">{{ __('Reset') }}</a>
                                </form>
                                @if(!$orders->isEmpty())
                                    <button type="button" class="btn btn-light border" id="crm-orders-in-excel-btn" title="{{ __('Export Excel') }}">
                                        <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if($orders->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No incoming orders. Orders sent to you by customers will appear here.') }}</p>
                    @else
                    <div class="table-responsive orders-in-table-wrap">
                        <table class="table mb-0 crm-datatable" data-export-name="orders-in" id="kt_table_orders_in" data-dt-hide-buttons-ui="1">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">{{ __('Order Code') }}</th>
                                    <th>{{ __('Ref. Num') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th class="text-center">{{ __('Qty') }}</th>
                                    <th class="no-export">{{ __('Image') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-center">{{ __('Seen') }}</th>
                                    <th class="text-center">{{ __('Quotation Sent') }}</th>
                                    <th>{{ __('Ordered By') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th class="text-end actions-col no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                @php
                                    $order_supplier = App\Models\OrderSuppliers::where('order_id', $order->id)->where('supplier', Auth::user()->id)->first();
                                    $orderQuotation = App\Models\OrderQuotations::where('supplier_user_id', Auth::user()->id)->where('order_id', $order->id)->first();
                                @endphp
                                <tr>
                                    <td class="ps-4"><span class="fw-medium">{{ $order->code }}</span></td>
                                    <td><span class="text-muted">{{ $order->ref_number ?? '—' }}</span></td>
                                    <td>
                                        @if($order->product)
                                            <a href="{{ route('products.show', $order->product->id) }}" target="_blank" class="text-primary">{{ str($order->product->name)->limit(20) }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center"><span class="badge bg-primary-subtle text-primary">{{ formatNumber($order->quantity) }}</span></td>
                                    <td class="no-export">
                                        @php $imgSrc = order_image($order); $imgHref = $order->image ? (str_starts_with($order->image, 'http') ? $order->image : asset('/storage/uploads/orders/original/'.$order->image)) : $imgSrc; @endphp
                                        <a href="{{ $imgHref }}" target="_blank"><img src="{{ $imgSrc }}" alt="" class="rounded" style="width:50px;height:50px;object-fit:cover;" onerror="this.onerror=null; this.src='{{ placeholder_image('order') }}';"></a>
                                    </td>
                                    <td>
                                        @php $statusClass = match(strtolower($order->status ?? '')) { 'pending' => 'warning', 'accepted'=> 'success', 'deal'=> 'success', 'completed'=> 'success', 'rejected'=> 'danger', 'cancelled'=> 'danger', default => 'secondary' }; @endphp
                                        <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($order->status) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if($order_supplier && $order_supplier->seen == 0)
                                            <span class="badge bg-danger-subtle text-danger fs-7">{{ __('Not seen') }}</span>
                                        @elseif($order_supplier && $order_supplier->seen == 1)
                                            <span class="badge bg-success-subtle text-success fs-7">{{ __('Seen') }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($orderQuotation)
                                            <span class="badge bg-success-subtle text-success fs-7">{{ __('Yes') }}</span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger fs-7">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $hasStoreCustomer = !empty($order->customer_name) || !empty($order->customer_email);
                                            $custName = $order->customer_name ?? optional($order->user)->name ?? '—';
                                        @endphp
                                        @if(!$hasStoreCustomer && $order->user && $order->user->profile)
                                            <a href="{{ route('profile.show', $order->user->profile->username) }}" target="_blank" class="text-body">{{ $custName }}</a>
                                        @else
                                            <span class="text-muted">{{ $custName }}</span>
                                        @endif
                                    </td>
                                    <td><span class="text-muted fs-7">{{ $order->created_at?->format('d M, Y') }}</span></td>
                                    <td class="text-end actions-col no-export">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <a href="{{ route('orders.show', $order->code) }}" class="list-action-btn bg-info-subtle text-info" title="{{ __('View') }}"><i class="las la-eye fs-18"></i></a>
                                            @if(!OrderSupplierCheck($order->id))
                                                <a href="{{ route('orders.edit', $order->code) }}" class="list-action-btn bg-primary-subtle text-primary" title="{{ __('Edit') }}"><i class="las la-pen fs-18"></i></a>
                                                <form action="{{ route('orders.destroy', $order->code) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this order?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="list-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></button>
                                                </form>
                                            @endif
                                        </div>
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
            var tableEl = document.getElementById('kt_table_orders_in');
            var excelBtn = document.getElementById('crm-orders-in-excel-btn');
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
