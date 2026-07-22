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
                            <h4 class="card-title">{{ __('My deals in') }}</h4>
                            <p class="text-muted mb-0 small">{{ __('Accepted deals as supplier.') }}</p>
                        </div>
                        <div class="col-auto">
                            @if(!$quotations->isEmpty())
                                <button type="button" class="btn btn-light border" id="crm-deals-in-excel-btn" title="{{ __('Export Excel') }}">
                                    <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if($quotations->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No deals yet.') }}</p>
                    @else
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="deals-in" id="kt_table_deals_in" data-dt-hide-buttons-ui="1" data-dt-order='[[7,"desc"]]'>
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">{{ __('Customer') }}</th>
                                    <th>{{ __('Order') }}</th>
                                    <th>{{ __('Price') }}</th>
                                    <th>{{ __('Delivery Date') }}</th>
                                    <th>{{ __('Notes') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Seen') }}</th>
                                    <th>{{ __('Created') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($quotations as $quotation)
                                @php
                                    $cust = $quotation->customer;
                                    $custName = optional($quotation->order)->customer_name ?? optional($cust)->name;
                                @endphp
                                <tr>
                                    <td class="ps-4">
                                        @if($custName)
                                            @if($cust && $cust->profile)
                                                <a href="{{ route('profile.show', $cust->profile->username) }}" target="_blank" class="fw-medium text-primary">{{ $custName }}</a>
                                            @else
                                                <span class="text-muted">{{ $custName }}</span>
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($quotation->order)
                                            <a class="fw-medium text-primary" href="{{ route('orders.show', $quotation->order->code) }}" target="_blank">{{ $quotation->order->code }}</a>
                                            @if(!empty($quotation->order->ref_number))<div class="fs-7 text-muted">{{ __('Ref.') }} {{ $quotation->order->ref_number }}</div>@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ formatNumber($quotation->price, 2) }}</td>
                                    <td>{{ $quotation->delivery_date }}</td>
                                    <td class="fs-7">{{ str($quotation->notes ?? '')->limit(60) }}</td>
                                    <td><span class="badge bg-success-subtle text-success">{{ ucfirst($quotation->status ?? '') }}</span></td>
                                    <td>
                                        @if((int) $quotation->seen === 0)
                                            <span class="text-danger fs-7">{{ __('Not seen yet') }}</span>
                                        @else
                                            <span class="text-success fs-7">{{ __('Seen') }}</span>
                                        @endif
                                    </td>
                                    <td class="fs-7 text-muted">{{ $quotation->created_at?->format('d M, Y H:i') }}</td>
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
            var tableEl = document.getElementById('kt_table_deals_in');
            var excelBtn = document.getElementById('crm-deals-in-excel-btn');
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
