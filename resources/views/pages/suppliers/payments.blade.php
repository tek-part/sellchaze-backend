<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center flex-wrap gap-3">
                            <div class="col">
                                <h2 class="fs-4 fw-bold mb-1">{{ __('Supplier Payments') }}</h2>
                                <p class="text-muted mb-0 fs-6">{{ __('View supplier balances and payment history.') }}</p>
                            </div>
                            <div class="col-auto">
                                <form method="GET" class="d-flex gap-2 align-items-center">
                                    <select name="supplier_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                        <option value="">{{ __('All Suppliers') }}</option>
                                        @foreach ($balances as $b)
                                            <option value="{{ $b->supplier->id }}" {{ request('supplier_id') == $b->supplier->id ? 'selected' : '' }}>{{ $b->supplier->name }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if(empty($balances))
                            <div class="text-center py-5">
                                <i class="iconoir-hand-card fs-1 text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-0">{{ __('No suppliers with accepted quotations.') }}</p>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0 crm-datatable" data-export-name="supplier-payments">
                                    <thead class="table-light">
                                        <tr class="text-start fw-semibold fs-7 text-uppercase">
                                            <th class="ps-4 py-3 rounded-start">{{ __('Supplier') }}</th>
                                            <th class="py-3">{{ __('Total Orders') }}</th>
                                            <th class="py-3">{{ __('Total Payments') }}</th>
                                            <th class="py-3">{{ __('Balance') }}</th>
                                            <th class="py-3 pe-4 rounded-end text-end">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($balances as $b)
                                            <tr>
                                                <td class="ps-4 py-3">
                                                    <a href="{{ route('profile.show', optional($b->supplier->profile)->username ?? $b->supplier->id) }}" class="fw-semibold text-body text-decoration-none">{{ $b->supplier->name }}</a>
                                                </td>
                                                <td class="py-3"><span class="text-body">{{ formatNumber($b->total_orders, 2) }}</span></td>
                                                <td class="py-3"><span class="text-body">{{ formatNumber($b->total_payments, 2) }}</span></td>
                                                <td class="py-3"><span class="fw-bold {{ $b->balance >= 0 ? 'text-success' : 'text-danger' }}">{{ formatNumber($b->balance, 2) }}</span></td>
                                                <td class="py-3 pe-4 text-end">
                                                    @can('suppliers-payments-manage')
                                                    @if($b->balance > 0)
                                                    <button type="button" class="btn btn-sm btn-success me-1" data-bs-toggle="modal" data-bs-target="#addPaymentModal{{ $b->supplier->id }}"><i class="las la-plus me-1"></i> {{ __('Add Payment') }}</button>
                                                    <div class="modal fade" id="addPaymentModal{{ $b->supplier->id }}" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <form method="post" action="{{ route('suppliers.payments.store') }}">
                                                                    @csrf
                                                                    <input type="hidden" name="supplier_id" value="{{ $b->supplier->id }}">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">{{ __('Add Payment') }} - {{ $b->supplier->name }}</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">{{ __('Amount') }}</label>
                                                                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required placeholder="{{ __('Balance') }}: {{ formatNumber($b->balance, 2) }}">
                                                                        </div>
                                                                        <div class="mb-0">
                                                                            <label class="form-label">{{ __('Notes') }}</label>
                                                                            <input type="text" name="notes" class="form-control" placeholder="{{ __('Optional') }}">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                                                        <button type="submit" class="btn btn-primary"><i class="las la-check me-1"></i> {{ __('Record Payment') }}</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    @endif
                                                    @endcan
                                                    <a href="{{ route('suppliers.payments.transactions', $b->supplier->id) }}" class="btn btn-sm btn-primary me-1" title="{{ __('Payment History') }}"><i class="las la-history me-1"></i> {{ __('History') }}</a>
                                                    <a href="{{ route('orders.supplier', $b->supplier->id) }}" class="btn btn-sm btn-light" title="{{ __('View Orders') }}"><i class="las la-eye me-1"></i> {{ __('Orders') }}</a>
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
