<!--begin::Card header-->
<div class="card-header border-0 pt-6">
    <div class="card-title">
        <form method="GET" action="{{ route('orders.out') }}" id="kt_orders_out_filter_form" class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center position-relative">
                <span class="svg-icon svg-icon-1 position-absolute ms-4">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor" />
                        <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor" />
                    </svg>
                </span>
                <input type="text" name="search" class="form-control form-control-solid w-250px ps-12" placeholder="{{ __('Search') }} ({{ __('Order Code') }}, {{ __('Ref. Num') }}, {{ __('Product') }})" value="{{ ($filters ?? [])['search'] ?? '' }}" />
            </div>
            <select name="status" class="form-select form-select-solid w-150px" data-control="select2" data-placeholder="{{ __('Status') }}" data-allow-clear="true">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="pending" {{ (($filters ?? [])['status'] ?? '') === 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                <option value="accepted" {{ (($filters ?? [])['status'] ?? '') === 'accepted' ? 'selected' : '' }}>{{ __('Accepted') }}</option>
                <option value="completed" {{ (($filters ?? [])['status'] ?? '') === 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                <option value="rejected" {{ (($filters ?? [])['status'] ?? '') === 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                <option value="cancelled" {{ (($filters ?? [])['status'] ?? '') === 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
            </select>
            <input type="date" name="date_from" class="form-control form-control-solid w-150px" placeholder="{{ __('From date') }}" value="{{ ($filters ?? [])['date_from'] ?? '' }}" />
            <input type="date" name="date_to" class="form-control form-control-solid w-150px" placeholder="{{ __('To date') }}" value="{{ ($filters ?? [])['date_to'] ?? '' }}" />
            <button type="submit" class="btn btn-light-primary">{{ __('Apply') }}</button>
            <a href="{{ route('orders.out') }}" class="btn btn-light">{{ __('Reset') }}</a>
        </form>
    </div>
    <div class="card-toolbar">
        @can('orders-create')
        <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary">
            <span class="svg-icon svg-icon-2">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect opacity="0.5" x="11.364" y="20.364" width="16" height="2" rx="1" transform="rotate(-90 11.364 20.364)" fill="currentColor" />
                    <rect x="4.36396" y="11.364" width="16" height="2" rx="1" fill="currentColor" />
                </svg>
            </span>
            {{ __('Create order') }}
        </a>
        @endcan
    </div>
</div>
<!--end::Card header-->
