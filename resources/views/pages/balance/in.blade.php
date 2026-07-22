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
                            <h4 class="card-title">{{ __('Balance In') }}</h4>
                            <p class="text-muted mb-0 small">{{ __('Customer balances from accepted quotations.') }}</p>
                        </div>
                        <div class="col-auto">
                            @if(!$balances->isEmpty())
                                <button type="button" class="btn btn-light border" id="crm-balance-in-excel-btn" title="{{ __('Export Excel') }}">
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

                    @if($balances->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No balances. Customer balances will appear here.') }}</p>
                    @else
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="balance-in" id="kt_table_balance_in" data-dt-hide-buttons-ui="1">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">{{ __('Customer Name') }}</th>
                                    <th class="no-export">{{ __('Photo') }}</th>
                                    <th>{{ __('Number of orders') }}</th>
                                    <th>{{ __('Total Balance') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($balances as $balance)
                                @php
                                    $total_orders = App\Models\OrderSuppliers::whereHas('quotations', fn ($q) => $q->where('status', 'accepted'))->where('supplier', $balance->supplier_user_id)->where('customer', $balance->customer_user_id)->count();
                                    $customer = $users[$balance->customer_user_id] ?? null;
                                    $supplier = $users[$balance->supplier_user_id] ?? null;
                                    $customer_profile = $customer?->profile;
                                @endphp
                                @if($customer && $supplier && $customer_profile)
                                <tr>
                                    <td class="ps-4">
                                        <a href="{{ route('profile.show', $customer_profile->username) }}" target="_blank" class="fw-medium text-body">{{ $customer->name }}</a>
                                    </td>
                                    <td class="no-export">
                                        <img src="{{ user_photo($customer->id) }}" alt="" class="rounded" style="width:35px;height:35px;object-fit:cover" onerror="this.src='{{ placeholder_image('avatar') }}'" />
                                    </td>
                                    <td><span class="badge bg-primary-subtle text-primary">{{ formatNumber($total_orders) }}</span></td>
                                    <td><span class="fw-medium">{{ formatNumber($balance->total_balance, 2) }}</span></td>
                                    <td class="text-end">
                                        <form action="{{ route('balance.post.details') }}" method="POST" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="supplier" value="{{ encrypt($supplier->id) }}">
                                            <input type="hidden" name="customer" value="{{ encrypt($customer->id) }}">
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Details') }}</button>
                                        </form>
                                    </td>
                                </tr>
                                @endif
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
            var tableEl = document.getElementById('kt_table_balance_in');
            var excelBtn = document.getElementById('crm-balance-in-excel-btn');
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
