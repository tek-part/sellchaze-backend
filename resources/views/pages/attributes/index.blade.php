<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
    </style>
    @endpush
    <div class="row" data-bulk-prefix="attributes">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('All Attributes') }}</h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="button" class="btn btn-light border" id="crm-attributes-excel-btn" title="{{ __('Export Excel') }}">
                                    <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                </button>
                                @can('attributes-delete')
                                    <button type="button" class="btn btn-danger" id="attributes-bulk-delete-btn" disabled data-empty-msg="{{ __('Please select at least one attribute.') }}" data-confirm-msg="{{ __('Are you sure you want to delete the selected attributes?') }}">
                                        <i class="las la-trash-alt me-1"></i> {{ __('Delete selected') }}
                                    </button>
                                @endcan
                                @can('attributes-create')
                                    <a href="{{ route('attributes.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> {{ __('Add Attribute') }}</a>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session()->has('error'))
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ session()->get('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if(session()->has('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session()->get('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if($attributes->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No attributes found.') }}</p>
                    @else
                    @can('attributes-delete')
                        <form id="attributes-bulk-form" action="{{ route('attributes.bulk-destroy') }}" method="POST" class="d-none">
                            @csrf
                            <div id="attributes-bulk-ids"></div>
                        </form>
                    @endcan
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="attributes" id="kt_table_attributes" data-dt-hide-buttons-ui="1">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 16px;" class="no-export no-sort">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="select-all" id="attributes-select-all">
                                        </div>
                                    </th>
                                    <th>#</th>
                                    <th class="ps-0">{{ __('Name') }}</th>
                                    <th>{{ __('Values') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($attributes as $attribute)
                                <tr>
                                    <td style="width: 16px;" class="no-export">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input attributes-checkbox" name="check" value="{{ $attribute->id }}">
                                        </div>
                                    </td>
                                    <td>{{ $loop->iteration }}</td>
                                    <td class="ps-0"><span class="fw-medium text-body">{{ $attribute->name }}</span></td>
                                    <td>
                                        @if($attribute->attribute_values && $attribute->attribute_values->isNotEmpty())
                                            <span class="text-muted font-13">{{ $attribute->attribute_values->pluck('value')->join(', ') }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td><span class="badge bg-secondary-subtle text-secondary">{{ $attribute->type }}</span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            @can('attributes-edit')
                                                <a href="{{ route('attributes.edit', $attribute->id) }}" class="list-action-btn bg-primary-subtle text-primary" title="{{ __('Edit') }}"><i class="las la-pen fs-18"></i></a>
                                            @endcan
                                            @can('attributes-delete')
                                                <form action="{{ route('attributes.destroy', $attribute->id) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this attribute?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="list-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></button>
                                                </form>
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
        document.addEventListener('DOMContentLoaded', function() {
            var tableEl = document.getElementById('kt_table_attributes');
            var excelBtn = document.getElementById('crm-attributes-excel-btn');
            if (tableEl && excelBtn && typeof jQuery !== 'undefined') {
                function bindExcel() {
                    if (!jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(tableEl)) {
                        setTimeout(bindExcel, 50);
                        return;
                    }
                    var api = jQuery(tableEl).DataTable();
                    excelBtn.addEventListener('click', function() {
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
