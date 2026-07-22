<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Latest Orders') }}</h4>
                        </div>
                        <div class="col-auto">
                            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                                <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm w-auto" style="min-width: 160px;" placeholder="{{ __('Search') }}…" />
                                <select name="status" class="form-select form-select-sm w-auto" style="min-width: 140px;">
                                    <option value="">{{ __('All Statuses') }}</option>
                                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                    <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>{{ __('In Progress') }}</option>
                                    <option value="accepted" {{ request('status') === 'accepted' ? 'selected' : '' }}>{{ __('Accepted') }}</option>
                                    <option value="deal" {{ request('status') === 'deal' ? 'selected' : '' }}>{{ __('Deal') }}</option>
                                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
                                </select>
                                @if(isset($suppliers) && $suppliers->isNotEmpty())
                                <select name="supplier" class="form-select form-select-sm w-auto" style="min-width: 150px;">
                                    <option value="">{{ __('All Suppliers') }}</option>
                                    @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}" {{ request('supplier') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                    @endforeach
                                </select>
                                @endif
                                <button type="submit" class="btn btn-light btn-sm">{{ __('Apply') }}</button>
                                <a href="{{ route('orders.out') }}" class="btn btn-light btn-sm">{{ __('Reset') }}</a>
                                <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> {{ __('Add Order') }}</a>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if($orders->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No orders found.') }}</p>
                    @else
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="orders-admin">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                <tr>
                                    <td><a href="{{ route('orders.show', $order->code) }}" class="text-primary">{{ $order->code }}</a></td>
                                    <td>
                                        @if($order->product)
                                            <p class="d-inline-block align-middle mb-0">
                                                <span class="d-block align-middle mb-0 product-name text-body">{{ str($order->product->name)->limit(40) }}</span>
                                                <a href="{{ route('products.show', $order->product->id) }}" class="text-muted font-13">{{ __('View product') }}</a>
                                            </p>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $order->customer_name ?? optional($order->user)->name ?? '—' }}</td>
                                    <td>
                                        @php
                                            $status = strtolower($order->status ?? '');
                                            $badgeClass = match($status) {
                                                'pending' => 'bg-secondary-subtle text-secondary',
                                                'accepted', 'deal', 'completed' => 'bg-success-subtle text-success',
                                                'rejected', 'cancelled' => 'bg-danger-subtle text-danger',
                                                default => 'bg-secondary-subtle text-secondary'
                                            };
                                        @endphp
                                        <span class="badge {{ $badgeClass }}">{{ ucfirst($order->status ?? '') }}</span>
                                    </td>
                                    <td>{{ $order->created_at?->format('d/m/Y') }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('orders.show', $order->code) }}" title="{{ __('View') }}"><i class="las la-eye text-secondary fs-18"></i></a>
                                        <a href="{{ route('orders.edit', $order->code) }}" title="{{ __('Edit') }}"><i class="las la-pen text-secondary fs-18 ms-1"></i></a>
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
