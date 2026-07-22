<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ isset($order) ? __('Edit Order') : __('Create Order') }}</h4>
                            <p class="mb-0 text-muted small">{{ isset($order) ? __('Update order details.') : __('Add a new order and select product, attributes and suppliers.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ isset($order) ? route('orders.show', $order->code) : route('orders.out') }}" class="btn btn-light btn-sm">
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
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    <form action="{{ isset($order) ? route('orders.update', $order->id) : route('orders.store') }}" method="POST" enctype="multipart/form-data" id="order-form">
                        @csrf
                        @if(isset($order))
                            @method('PUT')
                        @endif

                        <div class="row g-4">
                            <div class="col-md-6 col-lg-3">
                                <label for="category" class="form-label fw-semibold">{{ __('Category') }} <span class="text-danger">*</span></label>
                                <select class="form-select" name="category" id="category" required>
                                    <option value="">{{ __('Select Category') }}</option>
                                    @foreach($categories ?? [] as $category)
                                        <option value="{{ $category->id }}" {{ (isset($order) && $order->product && $order->product->category_id == $category->id) ? 'selected' : (old('category') == $category->id ? 'selected' : '') }}>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-3" id="products_holder" style="display:none;">
                                <label for="product" class="form-label fw-semibold">{{ __('Product') }} <span class="text-danger">*</span></label>
                                <div id="products"></div>
                            </div>
                            <div class="col-md-6 col-lg-3" id="attributes_holder" style="display:none;">
                                <label class="form-label fw-semibold">{{ __('Attributes') }} <span class="text-danger">*</span></label>
                                <div id="attributes"></div>
                            </div>
                            <div class="col-md-6 col-lg-3" id="suppliers_holder" style="display:none;">
                                <label class="form-label fw-semibold">{{ __('Suppliers') }}</label>
                                <div id="suppliers" class="border rounded p-3" style="max-height: 200px; overflow-y: auto;"></div>
                            </div>
                        </div>

                        <div class="row g-4 mt-2">
                            <div class="col-md-6 col-lg-3">
                                <label for="quantity" class="form-label fw-semibold">{{ __('Quantity') }} <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" id="quantity" class="form-control" value="{{ old('quantity', $order->quantity ?? '') }}" placeholder="{{ __('Enter quantity') }}" required min="1">
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label for="ref_number" class="form-label fw-semibold">{{ __('Reference Number') }}</label>
                                <input type="text" name="ref_number" id="ref_number" class="form-control" value="{{ old('ref_number', $order->ref_number ?? '') }}" placeholder="{{ __('Optional') }}">
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <label for="image" class="form-label fw-semibold">{{ __('Image') }}</label>
                                <input type="file" name="image" id="image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            </div>
                        </div>

                        <div class="mt-4">
                            <label for="notes" class="form-label fw-semibold">{{ __('Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="{{ __('Additional details (optional)') }}">{{ old('notes', $order->notes ?? '') }}</textarea>
                        </div>

                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ isset($order) ? __('Update Order') : __('Create Order') }}
                            </button>
                            <a href="{{ isset($order) ? route('orders.show', $order->code) : route('orders.out') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @push('rizz-js')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var orderData = @json(isset($order) && $order ? [
                'category_id' => optional($order->product)->category_id,
                'product_id' => $order->product_id
            ] : []);
            var catSelect = document.getElementById('category');
            if (catSelect && orderData.category_id) {
                catSelect.value = orderData.category_id;
                catSelect.dispatchEvent(new Event('change'));
            }
            $('select[name="category"]').on('change', function() {
                $('#products_holder').hide();
                var categoryID = $(this).val();
                if (categoryID) {
                    $.ajax({
                        url: '{{ url('getproducts') }}/' + encodeURIComponent(categoryID),
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            if (data && data.products) {
                                $('#products_holder').show();
                                $('#products').html(data.products);
                                if (orderData.product_id) {
                                    setTimeout(function() { $('select[name="product"]').val(orderData.product_id).trigger('change'); }, 100);
                                }
                            }
                        }
                    });
                } else {
                    $('#products_holder').hide();
                    $('#products').html('');
                    $('#attributes_holder').hide();
                    $('#attributes').html('');
                    $('#suppliers_holder').hide();
                    $('#suppliers').html('');
                }
            });
            if (orderData.category_id) { $('select[name="category"]').trigger('change'); }
        });
    </script>
    @endpush
</x-default-layout>
