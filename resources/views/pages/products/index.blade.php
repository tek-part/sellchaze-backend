<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .products-action-btn {
            width: 34px;
            height: 34px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 0.375rem;
        }
        .products-action-btn i { line-height: 1; }
    </style>
    @endpush
    <div class="row" data-bulk-prefix="products" data-bulk-checkbox=".product-checkbox">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Products') }}</h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <div class="dropdown">
                                    <a class="btn bg-primary-subtle text-primary dropdown-toggle d-flex align-items-center arrow-none" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false" data-bs-auto-close="outside">
                                        <i class="iconoir-filter-alt me-1"></i> {{ __('Filter') }}
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-start">
                                        <div class="p-2">
                                            <a class="dropdown-item py-1" href="{{ route('products.index') }}">{{ __('All') }}</a>
                                            @foreach($categories ?? [] as $cat)
                                                <a class="dropdown-item py-1" href="{{ route('products.index', ['category_id' => $cat->id]) }}">{{ $cat->name }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-light border" id="crm-products-excel-btn" title="{{ __('Export Excel') }}">
                                    <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                </button>
                                @can('products-delete')
                                    <button type="button" class="btn btn-danger" id="products-bulk-delete-btn" disabled data-empty-msg="{{ __('Please select at least one product.') }}" data-confirm-msg="{{ __('Are you sure you want to delete the selected products?') }}">
                                        <i class="las la-trash-alt me-1"></i> {{ __('Delete selected') }}
                                    </button>
                                @endcan
                                @can('products-create')
                                    <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> {{ __('Add Product') }}</a>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if($products->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No products yet') }}</p>
                    @else
                    @can('products-delete')
                        <form id="products-bulk-form" action="{{ route('products.bulk-destroy') }}" method="POST" class="d-none">
                            @csrf
                            <div id="products-bulk-ids"></div>
                        </form>
                    @endcan
                    <div class="table-responsive">
                        <table class="table mb-0 crm-datatable" data-export-name="products" id="kt_table_products" data-dt-hide-buttons-ui="1">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 16px;" class="no-export no-sort">
                                        <div class="form-check mb-0">
                                            <input type="checkbox" class="form-check-input" name="select-all" id="products-select-all">
                                        </div>
                                    </th>
                                    <th class="ps-0">{{ __('Product Name') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Attributes') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $product)
                                <tr>
                                    <td style="width: 16px;" class="no-export">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input product-checkbox" name="check" value="{{ $product->id }}">
                                        </div>
                                    </td>
                                    <td class="ps-0">
                                        @php $imgSrc = product_image($product); @endphp
                                        <img src="{{ $imgSrc }}" alt="" height="40" class="me-2 align-middle rounded" onerror="this.onerror=null; this.src='{{ placeholder_image('product') }}';">
                                        <p class="d-inline-block align-middle mb-0">
                                            <a href="{{ route('products.show', $product->id) }}" class="d-inline-block align-middle mb-0 product-name text-body">{{ str($product->name)->limit(40) }}</a>
                                            <br>
                                            <span class="text-muted font-13">ID: {{ $product->id }}</span>
                                        </p>
                                    </td>
                                    <td>
                                        @if($product->category)
                                            <a href="{{ route('categories.edit', $product->category->id) }}" class="text-primary">{{ $product->category->name }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $attrsCount = $product->product_attributes?->count() ?? \App\Models\ProductAttributes::where('product_id', $product->id)->count();
                                        @endphp
                                        {{ $attrsCount }}
                                    </td>
                                    <td>
                                        <span>{{ $product->created_at?->format('d M Y, H:ia') ?? '—' }}</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="{{ route('products.show', $product->id) }}" class="products-action-btn bg-info-subtle text-info" title="{{ __('View') }}"><i class="las la-eye fs-18"></i></a>
                                            @can('products-edit')
                                                <a href="{{ route('products.edit', encrypt($product->id)) }}" class="products-action-btn bg-primary-subtle text-primary" title="{{ __('Edit') }}"><i class="las la-pen fs-18"></i></a>
                                            @endcan
                                            @can('products-delete')
                                                <form action="{{ route('products.destroy', encrypt($product->id)) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure you want to delete this product?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="products-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></button>
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
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                var tableEl = document.getElementById('kt_table_products');
                var excelBtn = document.getElementById('crm-products-excel-btn');
                if (tableEl && excelBtn && typeof jQuery !== 'undefined') {
                    function bindExcel() {
                        if (!jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(tableEl)) {
                            setTimeout(bindExcel, 50);
                            return;
                        }
                        var api = jQuery(tableEl).DataTable();
                        excelBtn.addEventListener('click', function() {
                            try {
                                api.button('.buttons-excel').trigger();
                            } catch (e) {
                                var $hidden = jQuery(tableEl).closest('.dataTables_wrapper').find('.buttons-excel').first();
                                if ($hidden.length) $hidden.trigger('click');
                            }
                        });
                    }
                    bindExcel();
                }
            });
        })();
    </script>
    </x-slot>
</x-default-layout>
