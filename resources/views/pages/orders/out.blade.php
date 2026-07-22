<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
        .orders-out-table-wrap th.actions-col, .orders-out-table-wrap td.actions-col { position: sticky; right: 0; background: var(--bs-body-bg, #fff); white-space: nowrap; box-shadow: -4px 0 8px rgba(0,0,0,.06); z-index: 1; }
        .orders-out-table-wrap thead th.actions-col { background: var(--bs-light, #f1faff); }
        .orders-out-table-wrap tbody tr:hover td.actions-col { background: var(--bs-body-bg, #fff); }
    </style>
    @endpush
    <div class="row" data-bulk-prefix="orders-out" data-bulk-checkbox=".orders-out-checkbox">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Orders Out') }}</h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <form method="GET" action="{{ route('orders.out') }}" id="kt_orders_out_filter_form" class="d-flex flex-wrap align-items-center gap-2">
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
                                    <a href="{{ route('orders.out') }}" class="btn btn-light btn-sm">{{ __('Reset') }}</a>
                                </form>
                                @if(!$orders->isEmpty())
                                    <button type="button" class="btn btn-light border" id="crm-orders-out-excel-btn" title="{{ __('Export Excel') }}">
                                        <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                    </button>
                                    <button type="button" class="btn btn-danger" id="orders-out-bulk-delete-btn" disabled data-empty-msg="{{ __('Please select at least one order.') }}" data-confirm-msg="{{ __('Are you sure you want to delete the selected orders?') }}">
                                        <i class="las la-trash-alt me-1"></i> {{ __('Delete selected') }}
                                    </button>
                                @endif
                                @can('orders-create')
                                    <a href="{{ route('orders.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> {{ __('Create order') }}</a>
                                @endcan
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
                        <p class="text-muted text-center py-5 mb-0">{{ __('No orders yet. Orders you send to suppliers will appear here.') }}</p>
                    @else
                    <form id="orders-out-bulk-form" action="{{ route('orders.bulk-destroy') }}" method="POST" class="d-none">
                        @csrf
                        <div id="orders-out-bulk-ids"></div>
                    </form>
                    <div class="table-responsive orders-out-table-wrap">
                        <table class="table mb-0 crm-datatable" data-export-name="orders-out" id="kt_table_orders_out" data-dt-hide-buttons-ui="1">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 16px;" class="no-export no-sort">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="select-all" id="orders-out-select-all">
                                        </div>
                                    </th>
                                    <th class="ps-4">{{ __('Order Code') }}</th>
                                    <th>{{ __('Ref. Num') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th class="text-center">{{ __('Qty') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="text-center">{{ __('Quotations') }}</th>
                                    @if(!empty($showMerchantRoutingOnOut) && isset($partnerSuppliers) && $partnerSuppliers->isNotEmpty())
                                    <th class="no-export no-sort" style="min-width: 200px;">{{ __('Store category / routing') }}</th>
                                    @endif
                                    <th>{{ __('Sent to') }}</th>
                                    <th class="no-export">{{ __('Image') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th class="text-end actions-col no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                <tr>
                                    <td style="width: 16px;" class="no-export">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input orders-out-checkbox" name="check" value="{{ $order->code }}">
                                        </div>
                                    </td>
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
                                    <td>
                                        @php $statusClass = match(strtolower($order->status ?? '')) { 'pending' => 'warning', 'accepted'=> 'success', 'deal'=> 'success', 'completed'=> 'success', 'rejected'=> 'danger', 'cancelled'=> 'danger', default => 'secondary' }; @endphp
                                        <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($order->status) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if($order->quotations->count() > 0)
                                            <a href="{{ route('orders.quotations', $order->code) }}" class="badge bg-success-subtle text-success text-decoration-none">{{ $order->quotations->count() }}</a>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $order->quotations->count() }}</span>
                                        @endif
                                    </td>
                                    @if(!empty($showMerchantRoutingOnOut) && isset($partnerSuppliers) && $partnerSuppliers->isNotEmpty())
                                    <td class="no-export align-top">
                                        @if($order->merchantIsPurchaseCustomer(auth()->user()))
                                            @php $wpCat = $order->wigpleasureStoreCategoryId(); @endphp
                                            <form method="POST" action="{{ route('orders.out.wigpleasure-routing', $order->code) }}" class="vstack gap-1">
                                                @csrf
                                                @if($wpCat)
                                                    <input type="hidden" name="wigpleasure_category_id" value="{{ $wpCat }}">
                                                    <span class="small text-muted">{{ __('Category') }} #{{ $wpCat }}</span>
                                                @else
                                                    <input type="number" name="wigpleasure_category_id" class="form-control form-control-sm" min="1" placeholder="{{ __('Wigpleasure category ID') }}" required value="{{ old('wigpleasure_category_id') }}">
                                                @endif
                                                <select name="supplier_ids[]" class="form-select form-select-sm" multiple size="3" required aria-label="{{ __('Suppliers') }}">
                                                    @foreach($partnerSuppliers as $s)
                                                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" name="apply_to_order" value="1" id="apply-wp-{{ $order->id }}" checked>
                                                    <label class="form-check-label small" for="apply-wp-{{ $order->id }}">{{ __('Apply to this order') }}</label>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Save routing') }}</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                    @endif
                                    <td>
                                        @if($order->suppliers->isNotEmpty())
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach($order->suppliers as $supplier)
                                                    @php $quotation = App\Models\OrderQuotations::where('supplier_user_id', $supplier->supplier)->where('order_id', $order->id)->first(); $userInfo = getUserInfo($supplier->supplier); @endphp
                                                    @if($userInfo && $userInfo->profile)
                                                        <a href="{{ route('profile.show', $userInfo->profile->username) }}" target="_blank" class="d-inline-flex align-items-center gap-1 text-body text-decoration-none">
                                                            @if($quotation)
                                                                @if($quotation->status == 'pending')
                                                                    <span class="badge bg-warning-subtle text-warning rounded-circle p-1" title="{{ __('Pending') }}"><i class="fa fa-clock fs-8"></i></span>
                                                                @elseif($quotation->status == 'deal')
                                                                    <span class="badge bg-success-subtle text-success rounded-circle p-1" title="{{ __('Deal') }}"><i class="fa fa-check fs-8"></i></span>
                                                                @else
                                                                    <span class="badge bg-secondary-subtle rounded-circle p-1"><i class="fa fa-circle fs-8"></i></span>
                                                                @endif
                                                            @else
                                                                <span class="badge bg-danger-subtle text-danger rounded-circle p-1" title="{{ __('No quotation yet') }}"><i class="fa fa-times fs-8"></i></span>
                                                            @endif
                                                            <span class="fs-7">{{ $userInfo->name }}</span>
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="no-export">
                                        @php $imgSrc = order_image($order); $imgHref = $order->image ? (str_starts_with($order->image, 'http') ? $order->image : asset('/storage/uploads/orders/original/'.$order->image)) : $imgSrc; @endphp
                                        <a href="{{ $imgHref }}" target="_blank"><img src="{{ $imgSrc }}" alt="" class="rounded" style="width:50px;height:50px;object-fit:cover;" onerror="this.onerror=null; this.src='{{ placeholder_image('order') }}';"></a>
                                    </td>
                                    <td><span class="text-muted fs-7">{{ $order->created_at?->format('d M, Y') }}</span></td>
                                    <td class="text-end actions-col no-export">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <a href="{{ route('orders.show', $order->code) }}" class="list-action-btn bg-info-subtle text-info" title="{{ __('View') }}"><i class="las la-eye fs-18"></i></a>
                                            <a href="{{ route('orders.edit', $order->code) }}" class="list-action-btn bg-primary-subtle text-primary" title="{{ __('Edit') }}"><i class="las la-pen fs-18"></i></a>
                                            @if($order->quotations->count() > 0)
                                                <a href="{{ route('orders.quotations', $order->code) }}" class="list-action-btn bg-success-subtle text-success" title="{{ __('Quotations') }}"><i class="las la-list fs-18"></i></a>
                                            @endif
                                            <form action="{{ route('orders.destroy', $order->code) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this order?') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="list-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></button>
                                            </form>
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
            var tableEl = document.getElementById('kt_table_orders_out');
            var excelBtn = document.getElementById('crm-orders-out-excel-btn');
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
