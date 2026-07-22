<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            {{-- User header --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="d-flex align-items-center flex-row flex-wrap">
                                <div class="position-relative me-3">
                                    <img src="{{ user_photo($user->id, 'original', false) }}" alt="" height="64" class="rounded-circle" style="width:64px;height:64px;object-fit:cover;" onerror="this.src='{{ placeholder_image('avatar') }}'">
                                </div>
                                <div>
                                    <h5 class="fw-semibold fs-5 mb-1">{{ $user->name }}</h5>
                                    <p class="mb-0 text-muted small">{{ '@' . ($user->profile->username ?? '—') }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('profile.show', [$user->profile->username ?? null, encrypt($user->id)]) }}" class="btn btn-light">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back to profile') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show mb-3">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show mb-3">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show mb-3">
                    <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            {{-- Assign products form --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('Assign products') }}</h4>
                </div>
                <div class="card-body pt-0">
                    <form action="{{ route('profile.post.assign.products') }}" method="POST" id="assign-products-form">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $user_id_encrypted }}">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Category') }}</label>
                                <select class="form-select category" id="assign-category" data-id="1">
                                    <option value="">{{ __('Select category') }}</option>
                                    @foreach($categories ?? [] as $category)
                                        <option value="{{ $category->id }}" {{ (string)$selected_category === (string)$category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-8 mb-3 products_holder_1" style="{{ count($selected_products ?? []) > 0 ? '' : 'display:none;' }}">
                                <label class="form-label d-block">{{ __('Products') }}</label>
                                <div id="products-container" class="d-flex flex-column gap-2">
                                    @if(!empty($selected_products))
                                        @foreach($selected_products as $sCatId => $selectedProductIds)
                                            @php
                                                $prods = \App\Models\Product::where('category_id', $sCatId)->get();
                                                $pids = is_array($selectedProductIds) ? $selectedProductIds : [];
                                            @endphp
                                            @if($prods->isNotEmpty())
                                            <div class="products products_{{ $sCatId }}" style="display:{{ (string)$sCatId === (string)($selected_category ?? '') ? 'block' : 'none' }};">
                                                @foreach($prods as $product)
                                                    <div class="form-check">
                                                        <input type="checkbox" name="products[{{ $sCatId }}][{{ $product->id }}]" class="form-check-input" id="product-{{ $product->id }}" value="{{ $product->id }}" @checked(in_array($product->id, $pids))>
                                                        <label class="form-check-label" for="product-{{ $product->id }}">{{ $product->name }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Save assignments') }}
                            </button>
                            <a href="{{ route('profile.show', [$user->profile->username ?? null, encrypt($user->id)]) }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Assigned products table --}}
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">{{ __('Assigned products') }}</h4>
                </div>
                <div class="card-body pt-0">
                    @if($assigned_products->isEmpty())
                        <p class="text-muted text-center py-5 mb-0">{{ __('No products assigned yet. Use the form above to assign products.') }}</p>
                    @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-0">{{ __('Product') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Attributes') }}</th>
                                    <th class="text-end">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assigned_products as $product)
                                <tr>
                                    <td class="ps-0">
                                        @php $imgSrc = product_image($product); @endphp
                                        <img src="{{ $imgSrc }}" alt="" height="40" class="me-2 align-middle rounded" onerror="this.onerror=null; this.src='{{ placeholder_image('product') }}';">
                                        <a href="{{ route('products.show', $product->id) }}" class="text-body fw-medium">{{ str($product->name)->limit(40) }}</a>
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
                                    <td class="text-end">
                                        <a href="{{ route('products.show', $product->id) }}" class="btn btn-light btn-sm">
                                            <i class="las la-eye me-1"></i> {{ __('View') }}
                                        </a>
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
        $('#assign-category').on('change', function() {
            var index = $(this).attr('data-id');
            $('.products_holder_' + index).show();
            var categoryID = $(this).val();
            if (categoryID) {
                $.ajax({
                    url: '{{ url("getmultiproducts") }}/' + encodeURIComponent(categoryID),
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        if (data && data.products) {
                            if ($('.products_' + categoryID).length === 0) {
                                $('#products-container').append('<div class="products products_' + categoryID + '">' + data.products + '</div>');
                            }
                            $('.products').hide();
                            $('.products_' + categoryID).show();
                        }
                    }
                });
            } else {
                $('.products_holder_' + index).hide();
            }
        });
    });
    </script>
    </x-slot>
</x-default-layout>
