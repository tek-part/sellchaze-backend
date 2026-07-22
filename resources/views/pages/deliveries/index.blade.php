<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center flex-wrap gap-3">
                            <div class="col">
                                <h2 class="fs-4 fw-bold mb-1">{{ __('Deliveries') }}</h2>
                                <p class="text-muted mb-0 fs-6">{{ __('Filter deliveries and update status.') }}</p>
                            </div>
                            <div class="col-auto">
                                <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                                    <select name="company" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                        <option value="">{{ __('All Companies') }}</option>
                                        <option value="aramex" {{ request('company') === 'aramex' ? 'selected' : '' }}>Aramex</option>
                                        <option value="careem" {{ request('company') === 'careem' ? 'selected' : '' }}>Careem</option>
                                        <option value="yahiya" {{ request('company') === 'yahiya' ? 'selected' : '' }}>Yahiya</option>
                                        <option value="manual" {{ request('company') === 'manual' ? 'selected' : '' }}>{{ __('Manual') }}</option>
                                    </select>
                                    <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                        <option value="in_transit" {{ request('status') === 'in_transit' ? 'selected' : '' }}>{{ __('In Transit') }}</option>
                                        <option value="delivered" {{ request('status') === 'delivered' ? 'selected' : '' }}>{{ __('Delivered') }}</option>
                                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                                    </select>
                                    <a href="{{ route('deliveries.index') }}" class="btn btn-sm btn-light">{{ __('Reset') }}</a>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($deliveries->isEmpty())
                            <div class="text-center py-5">
                                <i class="iconoir-delivery-truck fs-1 text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-0">{{ __('No deliveries found.') }}</p>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0 crm-datatable" data-export-name="deliveries">
                                    <thead class="table-light">
                                        <tr class="text-start fw-semibold fs-7 text-uppercase">
                                            <th class="ps-4 py-3 rounded-start">{{ __('Order') }}</th>
                                            <th class="py-3">{{ __('Company') }}</th>
                                            <th class="py-3">{{ __('Tracking') }}</th>
                                            <th class="py-3">{{ __('Status') }}</th>
                                            <th class="py-3">{{ __('COD Amount') }}</th>
                                            <th class="py-3">{{ __('Delivered At') }}</th>
                                            <th class="py-3 pe-4 rounded-end text-end no-export no-sort">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($deliveries as $d)
                                            <tr>
                                                <td class="ps-4 py-3">
                                                    <a href="{{ route('orders.show', optional($d->order)->code) }}" class="fw-semibold text-body text-decoration-none">{{ optional($d->order)->code ?? '—' }}</a>
                                                </td>
                                                <td class="py-3"><span class="text-body">{{ ucfirst($d->delivery_company) }}</span></td>
                                                <td class="py-3"><span class="text-muted">{{ $d->tracking_number ?? '—' }}</span></td>
                                                <td class="py-3">
                                                    <span class="badge badge-light-{{ $d->status === 'delivered' ? 'success' : ($d->status === 'failed' ? 'danger' : 'primary') }}">{{ ucfirst(str_replace('_', ' ', $d->status)) }}</span>
                                                </td>
                                                <td class="py-3">{{ $d->cod_amount ? formatNumber($d->cod_amount, 2) : '—' }}</td>
                                                <td class="py-3"><span class="text-muted fs-7">{{ $d->delivered_at ? $d->delivered_at->format('d M, Y H:i') : '—' }}</span></td>
                                                <td class="py-3 pe-4 text-end">
                                                    <form action="{{ route('deliveries.update-status', $d->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                            <option value="pending" {{ $d->status === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                                            <option value="in_transit" {{ $d->status === 'in_transit' ? 'selected' : '' }}>{{ __('In Transit') }}</option>
                                                            <option value="delivered" {{ $d->status === 'delivered' ? 'selected' : '' }}>{{ __('Delivered') }}</option>
                                                            <option value="failed" {{ $d->status === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                                                        </select>
                                                    </form>
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
