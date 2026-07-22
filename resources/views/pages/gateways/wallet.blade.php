<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center flex-wrap gap-3">
                            <div class="col">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="thumb-lg rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                                        <i class="{{ $gateway->iconClass() }} fs-2"></i>
                                    </div>
                                    <div>
                                        <h2 class="fs-4 fw-bold mb-1">{{ $gateway->name }} {{ __('Wallet') }}</h2>
                                        <p class="text-muted mb-0 fs-6">{{ __('Transactions and balance adjustments.') }}</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="d-flex align-items-center gap-2">
                                    @can('gateways-edit')
                                        <a href="{{ route('gateways.edit', $gateway->slug) }}" class="btn btn-sm btn-light"><i class="las la-pen me-1"></i> {{ __('Edit') }}</a>
                                    @endcan
                                    @can('gateways-delete')
                                        <form action="{{ route('gateways.destroy', $gateway->slug) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this payment gateway?') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="las la-trash-alt me-1"></i> {{ __('Delete') }}</button>
                                        </form>
                                    @endcan
                                    <a href="{{ route('gateways.index') }}" class="btn btn-sm btn-primary"><i class="iconoir-arrow-left me-1"></i> {{ __('Back to Gateways') }}</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger alert-dismissible fade show mb-4">
                                {{ $errors->first() }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        {{-- Balance and monthly summary --}}
                        <div class="row mb-5">
                            <div class="col-md-3">
                                <div class="card border shadow-none bg-primary-subtle">
                                    <div class="card-body">
                                        <p class="text-muted mb-1 fs-7">{{ __('Current Balance') }}</p>
                                        <h3 class="fw-bold mb-0 fs-2 text-primary">{{ formatNumber($wallet->balance ?? 0, 2) }}</h3>
                                    </div>
                                </div>
                            </div>
                            @if(isset($month) && isset($year))
                            <div class="col-md-3">
                                <div class="card border shadow-none bg-success-subtle">
                                    <div class="card-body">
                                        <p class="text-muted mb-1 fs-7">{{ __('Month Orders Total') }}</p>
                                        <h3 class="fw-bold mb-0 fs-2 text-success">{{ formatNumber($selectedMonthTotal ?? 0, 2) }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border shadow-none bg-warning-subtle">
                                    <div class="card-body">
                                        <p class="text-muted mb-1 fs-7">{{ __('Month Fees / Commission') }}</p>
                                        <h3 class="fw-bold mb-0 fs-2 text-warning">{{ formatNumber($selectedMonthFees ?? 0, 2) }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border shadow-none bg-info-subtle">
                                    <div class="card-body">
                                        <p class="text-muted mb-1 fs-7">{{ __('Month Deposits') }}</p>
                                        <h3 class="fw-bold mb-0 fs-2 text-info">{{ formatNumber($selectedMonthDeposits ?? 0, 2) }}</h3>
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>

                        {{-- Month filter --}}
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <form method="GET" action="{{ route('gateways.wallet', $gateway->slug) }}" class="d-flex gap-2 align-items-end">
                                    <div class="flex-grow-1">
                                        <label class="form-label fs-7">{{ __('Filter by month') }}</label>
                                        <div class="d-flex gap-2">
                                            <select name="month" class="form-select form-select-sm">
                                                <option value="">{{ __('All') }}</option>
                                                @for($m = 1; $m <= 12; $m++)
                                                    <option value="{{ $m }}" {{ (isset($month) && $month == $m) ? 'selected' : '' }}>{{ date('F', mktime(0,0,0,$m,1)) }}</option>
                                                @endfor
                                            </select>
                                            <select name="year" class="form-select form-select-sm">
                                                @for($y = date('Y'); $y >= date('Y') - 3; $y--)
                                                    <option value="{{ $y }}" {{ (isset($year) && $year == $y) ? 'selected' : '' }}>{{ $y }}</option>
                                                @endfor
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
                                    @if(isset($month) || isset($year))
                                    <a href="{{ route('gateways.wallet', $gateway->slug) }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
                                    @endif
                                </form>
                            </div>
                        </div>

                        {{-- Add / Deduct forms --}}
                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <div class="card border shadow-sm">
                                    <div class="card-header bg-transparent py-3">
                                        <h5 class="card-title mb-0 fs-6"><i class="icofont-plus-circle me-2 text-success"></i> {{ __('Add Amount') }}</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="{{ route('gateways.add', $gateway->slug) }}" method="POST" class="d-flex flex-column gap-3">
                                            @csrf
                                            <div>
                                                <label class="form-label">{{ __('Amount') }}</label>
                                                <input type="number" name="amount" step="0.01" min="0.01" class="form-control" required>
                                            </div>
                                            <div>
                                                <label class="form-label">{{ __('Notes') }}</label>
                                                <input type="text" name="notes" class="form-control" placeholder="{{ __('Manual adjustment') }}">
                                            </div>
                                            <button type="submit" class="btn btn-success"><i class="iconoir-plus me-1"></i> {{ __('Add') }}</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border shadow-sm">
                                    <div class="card-header bg-transparent py-3">
                                        <h5 class="card-title mb-0 fs-6"><i class="icofont-minus-circle me-2 text-warning"></i> {{ __('Deduct (Fee / Commission)') }}</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="{{ route('gateways.deduct', $gateway->slug) }}" method="POST" class="d-flex flex-column gap-3">
                                            @csrf
                                            <div>
                                                <label class="form-label">{{ __('Amount') }}</label>
                                                <input type="number" name="amount" step="0.01" min="0.01" class="form-control" required>
                                            </div>
                                            <div>
                                                <label class="form-label">{{ __('Notes') }}</label>
                                                <input type="text" name="notes" class="form-control" placeholder="{{ __('Gateway fee') }}">
                                            </div>
                                            <button type="submit" class="btn btn-warning"><i class="iconoir-minus me-1"></i> {{ __('Deduct') }}</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Monthly report summary (last 12 months) --}}
                        @if(isset($monthlyOrderPayments) && $monthlyOrderPayments->isNotEmpty())
                        <div class="mb-5">
                            <h5 class="mb-3 fw-semibold">{{ __('Monthly Order Payments Summary') }}</h5>
                            <div class="table-responsive rounded border mb-0">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3 py-2">{{ __('Month') }}</th>
                                            <th class="py-2 pe-3 text-end">{{ __('Total') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($monthlyOrderPayments as $row)
                                        <tr>
                                            <td class="ps-3 py-2">{{ \Carbon\Carbon::createFromDate($row->year, $row->month)->translatedFormat('F Y') }}</td>
                                            <td class="py-2 pe-3 text-end fw-semibold text-success">{{ formatNumber($row->total ?? 0, 2) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        @endif

                        {{-- Transactions table --}}
                        <h5 class="mb-3 fw-semibold">{{ __('Transactions') }}</h5>
                        @if($transactions->isEmpty())
                            <div class="card border shadow-none">
                                <div class="card-body text-center py-5">
                                    <i class="iconoir-receipt fs-1 text-muted mb-3 d-block"></i>
                                    <p class="text-muted mb-0">{{ __('No transactions yet.') }}</p>
                                </div>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0 crm-datatable" data-export-name="gateway-{{ $gateway->slug }}-transactions" data-dt-order='[[0,"desc"]]'>
                                    <thead class="table-light">
                                        <tr class="text-start fw-semibold fs-7 text-uppercase">
                                            <th class="ps-4 py-3 rounded-start">{{ __('Date') }}</th>
                                            <th class="py-3">{{ __('Type') }}</th>
                                            <th class="py-3">{{ __('Amount') }}</th>
                                            <th class="py-3 pe-4 rounded-end">{{ __('Notes') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($transactions as $tx)
                                            <tr>
                                                <td class="ps-4 py-3"><span class="text-body fs-7">{{ $tx->created_at->format('d M, Y H:i') }}</span></td>
                                                <td class="py-3"><span class="badge bg-primary-subtle text-primary">{{ ucfirst($tx->type) }}</span></td>
                                                <td class="py-3"><span class="fw-semibold {{ $tx->amount >= 0 ? 'text-success' : 'text-danger' }}">{{ $tx->amount >= 0 ? '+' : '' }}{{ formatNumber($tx->amount, 2) }}</span></td>
                                                <td class="py-3 pe-4"><span class="text-muted">{{ $tx->notes ?? '—' }}</span></td>
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
