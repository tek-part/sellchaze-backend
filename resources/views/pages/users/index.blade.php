<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
    </style>
    @endpush
    <div class="row" data-bulk-prefix="users">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Users') }}</h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <form method="get" action="{{ route('users.index') }}" class="d-flex flex-wrap align-items-center gap-2">
                                    <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm w-auto" style="min-width: 160px;" placeholder="{{ __('Search') }}..." autocomplete="off">
                                    <select name="type" class="form-select form-select-sm w-auto" style="min-width: 130px;">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="merchant" {{ request('type') === 'merchant' ? 'selected' : '' }}>{{ __('Merchants') }}</option>
                                        <option value="supplier" {{ request('type') === 'supplier' ? 'selected' : '' }}>{{ __('Suppliers') }}</option>
                                        <option value="admin" {{ request('type') === 'admin' ? 'selected' : '' }}>{{ __('Admins') }}</option>
                                        <option value="pending" {{ request('type') === 'pending' ? 'selected' : '' }}>{{ __('Pending approval') }}</option>
                                    </select>
                                    <select name="active" class="form-select form-select-sm w-auto" style="min-width: 130px;">
                                        <option value="">{{ __('All statuses') }}</option>
                                        <option value="1" {{ request('active') === '1' ? 'selected' : '' }}>{{ __('Active') }}</option>
                                        <option value="0" {{ request('active') === '0' ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                                    </select>
                                    <button type="submit" class="btn btn-light btn-sm">{{ __('Apply') }}</button>
                                    <a href="{{ route('users.index') }}" class="btn btn-light btn-sm">{{ __('Reset') }}</a>
                                </form>
                                <button type="button" class="btn btn-light border" id="crm-users-excel-btn" title="{{ __('Export Excel') }}"><i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}</button>
                                @can('users-delete')
                                    <button type="button" class="btn btn-danger" id="users-bulk-delete-btn" disabled data-empty-msg="{{ __('Please select at least one user.') }}" data-confirm-msg="{{ __('Are you sure you want to delete the selected users?') }}">
                                        <i class="las la-trash-alt me-1"></i> {{ __('Delete selected') }}
                                    </button>
                                @endcan
                                @can('users-create')
                                    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> {{ __('Add User') }}</a>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($users->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No users found.') }}</p>
                    @else
                    @can('users-delete')
                        <form id="users-bulk-form" action="{{ route('users.bulk-destroy') }}" method="POST" class="d-none">
                            @csrf
                            <div id="users-bulk-ids"></div>
                        </form>
                    @endcan
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" id="kt_table_users" data-export-name="users" data-dt-hide-buttons-ui="1" data-dt-order='[[2,"asc"]]'>
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 16px;" class="no-export no-sort">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="select-all" id="users-select-all">
                                        </div>
                                    </th>
                                    <th class="no-export no-sort">{{ __('User') }}</th>
                                    <th>{{ __('Last login') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Activation') }}</th>
                                    <th>{{ __('Role') }}</th>
                                    <th>{{ __('Joined') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($users as $user)
                                <tr>
                                    <td style="width: 16px;" class="no-export">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input users-checkbox" name="check" value="{{ $user->id }}">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            @if(!empty($user->profile?->photo) || !empty($user->avatar))
                                                <img src="{{ user_photo($user->id) }}" alt="{{ $user->name }}" class="rounded-circle me-2" style="width:36px;height:36px;object-fit:cover;">
                                            @else
                                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;font-size:14px;font-weight:600;">{{ ucfirst(mb_substr($user->name, 0, 1)) }}</div>
                                            @endif
                                            <div>
                                                <span class="fw-medium text-body">{{ ucfirst($user->name) }}</span>
                                                <br><a href="mailto:{{ $user->email }}" class="text-muted font-13">{{ $user->email }}</a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($user->last_login_at == null)
                                            <span class="badge bg-danger-subtle text-danger">{{ __('Not logged in yet') }}</span>
                                        @else
                                            @php $days = \Carbon\Carbon::now()->diff($user->last_login_at)->days; @endphp
                                            @if($days < 1)
                                                <span class="badge bg-success-subtle text-success">{{ __('Today') }}</span>
                                            @elseif($days == 1)
                                                <span class="badge bg-primary-subtle text-primary">{{ __('1 day ago') }}</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">{{ $days }} {{ __('days ago') }}</span>
                                            @endif
                                            <br><span class="text-muted font-12">{{ $user->last_login_at }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(!empty($user->profile->online))
                                            <span class="badge bg-success-subtle text-success">{{ __('Online') }}</span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger">{{ __('Offline') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(!empty($user->profile->active))
                                            <span class="badge bg-success-subtle text-success">{{ __('Active') }}</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning">{{ __('Inactive') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse($user->roles as $role)
                                            @php
                                                $label = match($role->name) {
                                                    'Customer', 'Merchant' => __('Merchant'),
                                                    'Supplier' => __('Supplier'),
                                                    'Admin' => __('Admin'),
                                                    default => $role->name,
                                                };
                                            @endphp
                                            <span class="badge bg-primary-subtle text-primary">{{ $label }}</span>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td>{{ date('d/m/Y', strtotime($user->created_at)) }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            @if(!$user->hasRole('Admin') && (empty($user->profile->active) || $user->profile->active == 0))
                                                @can('users-edit')
                                                    {{ html()->form('POST', route('users.approve', $user->id))->class('d-inline')->open() }}
                                                        @csrf
                                                        <button type="submit" class="list-action-btn bg-success-subtle text-success" title="{{ __('Approve') }}"><i class="las la-check fs-18"></i></button>
                                                    {{ html()->form()->close() }}
                                                @endcan
                                                @can('users-edit')
                                                    {{ html()->form('POST', route('users.reject', $user->id))->class('d-inline')->open() }}
                                                        @csrf
                                                        <button type="submit" class="list-action-btn bg-warning-subtle text-warning" title="{{ __('Reject') }}"><i class="las la-times fs-18"></i></button>
                                                    {{ html()->form()->close() }}
                                                @endcan
                                            @elseif(!$user->hasRole('Admin'))
                                                @can('users-edit')
                                                    {{ html()->form('POST', route('users.reject', $user->id))->class('d-inline')->open() }}
                                                        @csrf
                                                        <button type="submit" class="list-action-btn bg-warning-subtle text-warning" title="{{ __('Reject') }}"><i class="las la-times fs-18"></i></button>
                                                    {{ html()->form()->close() }}
                                                @endcan
                                            @endif
                                            @php $username = !empty($user->profile->username) ? $user->profile->username : generateUserName($user->email); @endphp
                                            <a href="{{ route('profile.show', [$username, encrypt($user->id)]) }}" class="list-action-btn bg-primary-subtle text-primary" title="{{ __('Edit') }}"><i class="las la-pen fs-18"></i></a>
                                            @can('users-delete')
                                                {{ html()->form('DELETE', route('users.destroy', $user->id))->class('d-inline')->open() }}
                                                    <a href="#" role="button" data-rizz-confirm="{{ __('Are you sure to delete this user?') }}" class="list-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></a>
                                                {{ html()->form()->close() }}
                                            @endcan
                                        </div>
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

    <x-slot name="script">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tableEl = document.getElementById('kt_table_users');
            var excelBtn = document.getElementById('crm-users-excel-btn');
            if (tableEl && excelBtn && typeof jQuery !== 'undefined') {
                function bindExcel() {
                    if (!jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(tableEl)) {
                        setTimeout(bindExcel, 50);
                        return;
                    }
                    var api = jQuery(tableEl).DataTable();
                    excelBtn.addEventListener('click', function () {
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
