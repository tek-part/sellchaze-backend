<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 bg-transparent py-4">
                        <div class="row align-items-center flex-wrap gap-3">
                            <div class="col">
                                <h2 class="fs-4 fw-bold mb-1">{{ __('Order Tickets') }}</h2>
                                <p class="text-muted mb-0 fs-6">{{ __('Filter by status or type; export when needed.') }}</p>
                            </div>
                            <div class="col-auto">
                                <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                                    <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>{{ __('Open') }}</option>
                                        <option value="awaiting_supplier" {{ request('status') === 'awaiting_supplier' ? 'selected' : '' }}>{{ __('Awaiting Supplier') }}</option>
                                        <option value="supplier_responded" {{ request('status') === 'supplier_responded' ? 'selected' : '' }}>{{ __('Supplier Responded') }}</option>
                                        <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>{{ __('In Progress') }}</option>
                                        <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>{{ __('Resolved') }}</option>
                                        <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>{{ __('Closed') }}</option>
                                    </select>
                                    <select name="type" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                        <option value="">{{ __('All Types') }}</option>
                                        <option value="replacement" {{ request('type') === 'replacement' ? 'selected' : '' }}>{{ __('Replacement') }}</option>
                                        <option value="return" {{ request('type') === 'return' ? 'selected' : '' }}>{{ __('Return') }}</option>
                                        <option value="other" {{ request('type') === 'other' ? 'selected' : '' }}>{{ __('Other') }}</option>
                                    </select>
                                    <a href="{{ route('tickets.index') }}" class="btn btn-sm btn-light">{{ __('Reset') }}</a>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if ($message = Session::get('success'))
                            <div class="alert alert-success alert-dismissible fade show mb-4">
                                {{ $message }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif
                        @if($tickets->isEmpty())
                            <div class="text-center py-5">
                                <i class="iconoir-chat-bubble fs-1 text-muted mb-3 d-block"></i>
                                <p class="text-muted mb-0">{{ __('No tickets found.') }}</p>
                            </div>
                        @else
                            <div class="table-responsive rounded border">
                                <table class="table table-hover align-middle mb-0 crm-datatable" data-export-name="tickets" data-dt-order='[[0,"desc"]]'>
                                    <thead class="table-light">
                                        <tr class="text-start fw-semibold fs-7 text-uppercase">
                                            <th class="ps-4 py-3 rounded-start no-sort">ID</th>
                                            <th class="py-3">{{ __('Order') }}</th>
                                            <th class="py-3">{{ __('Type') }}</th>
                                            <th class="py-3">{{ __('Status') }}</th>
                                            <th class="py-3">{{ __('Requested By') }}</th>
                                            <th class="py-3">{{ __('Assigned To') }}</th>
                                            <th class="py-3">{{ __('Created') }}</th>
                                            <th class="py-3 pe-4 rounded-end text-end no-export no-sort">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tickets as $t)
                                            <tr>
                                                <td class="ps-4 py-3"><span class="fw-semibold text-body">#{{ $t->id }}</span></td>
                                                <td class="py-3">
                                                    <a href="{{ route('orders.show', optional($t->order)->code) }}" class="fw-semibold text-body text-decoration-none">{{ optional($t->order)->code ?? '—' }}</a>
                                                </td>
                                                <td class="py-3"><span class="badge bg-primary-subtle text-primary">{{ ucfirst($t->type) }}</span></td>
                                                <td class="py-3">
                                                    <span class="badge badge-light-primary">{{ ucfirst(str_replace('_', ' ', $t->status)) }}</span>
                                                </td>
                                                <td class="py-3"><span class="text-body">{{ optional($t->requester)->name ?? '—' }}</span></td>
                                                <td class="py-3"><span class="text-body">{{ optional($t->assignee)->name ?? '—' }}</span></td>
                                                <td class="py-3"><span class="text-muted fs-7">{{ $t->created_at->format('d M, Y H:i') }}</span></td>
                                                <td class="py-3 pe-4 text-end">
                                                    <a href="{{ route('tickets.show', $t->id) }}" class="btn btn-sm btn-primary" title="{{ __('View') }}"><i class="las la-eye me-1"></i> {{ __('View') }}</a>
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
