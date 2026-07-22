<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
        .quotations-table-wrap th.actions-col, .quotations-table-wrap td.actions-col { position: sticky; right: 0; background: var(--bs-body-bg, #fff); white-space: nowrap; box-shadow: -4px 0 8px rgba(0,0,0,.06); z-index: 1; }
        .quotations-table-wrap thead th.actions-col { background: var(--bs-light, #f1faff); }
        .quotations-table-wrap tbody tr:hover td.actions-col { background: var(--bs-body-bg, #fff); }
    </style>
    @endpush
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center w-100">
                        <div class="col">
                            <h4 class="card-title mb-0">{{ __('All quotations') }}</h4>
                        </div>
                        <div class="col-auto">
                            <a class="btn btn-light btn-sm" href="{{ route('orders.show', $order->code) }}" target="_blank">
                                <i class="las la-eye me-1"></i> {{ __('View order') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session()->has('error'))
                        <div class="alert alert-danger alert-dismissible fade show mb-3">
                            {{ session()->get('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if($order->quotations->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No quotations yet.') }}</p>
                    @else
                        <div class="table-responsive quotations-table-wrap">
                            <table class="table mb-0 crm-datatable" id="kt_table_order_quotations">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">{{ __('Order Code') }}</th>
                                        <th>{{ __('Ref. Num') }}</th>
                                        <th>{{ __('Supplier') }}</th>
                                        <th>{{ __('Price') }}</th>
                                        <th>{{ __('Delivery Date') }}</th>
                                        <th>{{ __('Notes') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Seen') }}</th>
                                        <th>{{ __('Created') }}</th>
                                        <th class="text-end actions-col no-sort">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($order->quotations as $quotation)
                                        @php
                                            $supplier = \App\Models\User::with('profile')->find($quotation->supplier_user_id);
                                            $statusClass = match(strtolower($quotation->status ?? '')) {
                                                'pending' => 'warning',
                                                'rejected' => 'danger',
                                                'accepted', 'deal', 'completed' => 'success',
                                                default => 'secondary'
                                            };
                                        @endphp
                                        <tr>
                                            <td class="ps-4">
                                                <a class="badge bg-info-subtle text-info text-decoration-none" href="{{ route('orders.show', $order->code) }}" target="_blank">{{ $order->code }}</a>
                                            </td>
                                            <td><span class="text-muted">{{ $order->ref_number ?? '—' }}</span></td>
                                            <td>
                                                @if($supplier && $supplier->profile)
                                                    <a href="{{ route('profile.show', $supplier->profile->username) }}" target="_blank" class="text-body text-decoration-none fw-medium">{{ $supplier->name }}</a>
                                                @else
                                                    <span class="text-muted">{{ $supplier?->name ?? '—' }}</span>
                                                @endif
                                            </td>
                                            <td><span class="fw-semibold">{{ formatNumber($quotation->price, 2) }}</span></td>
                                            <td><span class="text-muted">{{ $quotation->delivery_date ?? '—' }}</span></td>
                                            <td><span class="text-muted">{{ $quotation->notes ?: '—' }}</span></td>
                                            <td><span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }}">{{ ucfirst($quotation->status) }}</span></td>
                                            <td>
                                                @if((int) $quotation->seen === 0)
                                                    <span class="badge bg-danger-subtle text-danger">{{ __('Not seen') }}</span>
                                                @else
                                                    <span class="badge bg-success-subtle text-success">{{ __('Seen') }}</span>
                                                @endif
                                            </td>
                                            <td><span class="text-muted">{{ $quotation->created_at?->format('d M, Y') }}</span></td>
                                            <td class="text-end actions-col">
                                                @if($quotation->status === 'pending')
                                                    <form action="{{ route('quotations.confirm', encrypt($quotation->id)) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-success">{{ __('Confirm') }}</button>
                                                    </form>
                                                @else
                                                    <span class="badge bg-primary-subtle text-primary">{{ __('Action already taken') }}</span>
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
</x-default-layout>