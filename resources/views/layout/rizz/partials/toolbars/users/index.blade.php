@php
    $type = $type ?? request('type');
    $filters = $filters ?? ['search' => request('search'), 'type' => $type, 'active' => request('active')];
@endphp
<div class="card-header border-0 align-items-stretch py-5 flex-column flex-lg-row gap-4 crm-users-toolbar">
    <form method="get" action="{{ route('users.index') }}" id="crm-users-filter-form" class="d-flex flex-wrap align-items-center gap-2 gap-lg-3 flex-grow-1">
        <div class="d-flex align-items-center position-relative flex-grow-1 flex-lg-grow-0" style="min-width: 200px; max-width: 280px;">
            <span class="svg-icon svg-icon-1 position-absolute ms-3 z-index-1 text-gray-500">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor" />
                    <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor" />
                </svg>
            </span>
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control form-control-solid ps-10" placeholder="{{ __('Search name or email') }}" autocomplete="off">
        </div>
        <select name="type" class="form-select form-select-solid w-auto min-w-150px" aria-label="{{ __('Segment') }}">
            <option value="">{{ __('All segments') }}</option>
            <option value="merchant" {{ ($filters['type'] ?? '') === 'merchant' ? 'selected' : '' }}>{{ __('Merchants') }}</option>
            <option value="supplier" {{ ($filters['type'] ?? '') === 'supplier' ? 'selected' : '' }}>{{ __('Suppliers') }}</option>
            <option value="admin" {{ ($filters['type'] ?? '') === 'admin' ? 'selected' : '' }}>{{ __('Admins') }}</option>
        </select>
        <select name="active" class="form-select form-select-solid w-auto min-w-130px" aria-label="{{ __('Activation') }}">
            <option value="">{{ __('All activations') }}</option>
            <option value="1" {{ ($filters['active'] ?? '') === '1' ? 'selected' : '' }}>{{ __('Active only') }}</option>
            <option value="0" {{ ($filters['active'] ?? '') === '0' ? 'selected' : '' }}>{{ __('Inactive only') }}</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary px-4">
            <i class="fa fa-filter me-1"></i>{{ __('Apply') }}
        </button>
        <a href="{{ route('users.index') }}" class="btn btn-sm btn-light">{{ __('Reset') }}</a>
    </form>
    <div class="d-flex flex-wrap align-items-center gap-2 ms-lg-auto">
        <button type="button" class="btn btn-sm btn-light-primary border border-primary border-opacity-25" id="crm-users-excel-btn" title="{{ __('Export Excel') }}">
            <i class="fa fa-file-excel-o me-1"></i>{{ __('Excel') }}
        </button>
        @can('users-create')
            <a href="{{ route('users.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i>{{ __('Add User') }}
            </a>
        @endcan
    </div>
</div>
