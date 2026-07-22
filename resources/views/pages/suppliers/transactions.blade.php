<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center">
                            <div class="col">
                                <h2 class="fs-4 fw-bold mb-1">{{ __('Payment History') }} - {{ $supplier->name }}</h2>
                                <p class="text-muted mb-0 fs-6">{{ __('Total orders') }}: {{ formatNumber($totalOrders, 2) }} | {{ __('Total payments') }}: {{ formatNumber($totalPayments, 2) }} | {{ __('Balance') }}: <span class="fw-bold {{ $balance >= 0 ? 'text-success' : 'text-danger' }}">{{ formatNumber($balance, 2) }}</span></p>
                            </div>
                            <div class="col-auto">
                                <a href="{{ route('suppliers.payments') }}" class="btn btn-light btn-sm"><i class="las la-arrow-left me-1"></i> {{ __('Back') }}</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if(session('success'))
                            <div class="alert alert-success alert-dismissible fade show mb-4">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                        @endif
                        @if($payments->isEmpty())
                            <div class="text-center py-5">
                                <i class="iconoir-receipt fs-1 text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-0">{{ __('No payments recorded yet.') }}</p>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4 py-3">{{ __('Date') }}</th>
                                            <th class="py-3">{{ __('Amount') }}</th>
                                            <th class="py-3">{{ __('Notes') }}</th>
                                            <th class="py-3 pe-4">{{ __('Recorded by') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($payments as $p)
                                        <tr>
                                            <td class="ps-4 py-3">{{ $p->created_at->format('d M Y H:i') }}</td>
                                            <td class="py-3 fw-semibold text-success">{{ formatNumber($p->amount, 2) }}</td>
                                            <td class="py-3 text-muted">{{ $p->notes ?? '—' }}</td>
                                            <td class="py-3 pe-4">{{ optional($p->recorder)->name ?? '—' }}</td>
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
