<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Edit Product') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Update product details.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('products.index') }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('products.update', encrypt($product->id)) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="row g-4">
                            <div class="col-12">
                                <label for="name" class="form-label fw-semibold">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $product->name) }}" class="form-control" placeholder="{{ __('Product Name') }}" required>
                            </div>
                            <div class="col-12">
                                <label for="description" class="form-label fw-semibold">{{ __('Description') }} <span class="text-danger">*</span></label>
                                <textarea name="description" id="description" class="form-control" rows="5" placeholder="{{ __('Product Description') }}" required>{{ old('description', $product->description) }}</textarea>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="image" class="form-label fw-semibold">{{ __('Image') }}</label>
                                <input type="file" name="image" id="image" class="form-control" accept="image/*">
                                @if($product->image)
                                    <a href="{{ asset('/storage/uploads/products/original/'.$product->image) }}" target="_blank" class="d-inline-block mt-2">
                                        <img src="{{ product_image($product) }}" alt="" class="rounded border" style="max-width:150px;max-height:150px;object-fit:cover;" onerror="this.src='{{ placeholder_image('product') }}';">
                                    </a>
                                @endif
                            </div>
                            @if($categories->isNotEmpty())
                            <div class="col-md-6 col-lg-4">
                                <label for="category" class="form-label fw-semibold">{{ __('Category') }} <span class="text-danger">*</span></label>
                                <select name="category" id="category" class="form-select" required>
                                    <option value="">{{ __('Please Select') }}</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ ($category->id == $product->category_id || old('category') == $category->id) ? 'selected' : '' }}>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                            @if($attributes->isNotEmpty())
                            <div class="col-md-6 col-lg-4">
                                <label for="attributes" class="form-label fw-semibold">{{ __('Attributes') }}</label>
                                <select name="attributes[]" id="attributes" class="form-select" multiple>
                                    @foreach($attributes as $attribute)
                                        @php $hasAttr = App\Models\ProductAttributes::where('product_id', $product->id)->where('attribute_id', $attribute->id)->exists(); @endphp
                                        <option value="{{ $attribute->id }}" {{ ($hasAttr || in_array($attribute->id, old('attributes', []))) ? 'selected' : '' }}>{{ $attribute->name }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Hold Ctrl/Cmd to select multiple.') }}</div>
                            </div>
                            @endif
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Update') }}
                            </button>
                            <a href="{{ route('products.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
