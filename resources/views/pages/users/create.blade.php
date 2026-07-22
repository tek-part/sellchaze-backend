<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Add New User') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Create a new user account.') }}</p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('users.index') }}" class="btn btn-light btn-sm">
                                <i class="las la-arrow-left me-1"></i> {{ __('Back') }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <form action="{{ route('users.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf

                        @if ($errors->any())
                            <div class="alert alert-danger alert-dismissible fade show mb-4">
                                <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        <div class="row g-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="username" class="form-label fw-semibold">{{ __('Username') }}</label>
                                <input type="text" name="username" id="username" class="form-control" value="{{ old('username') }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label fw-semibold">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" required>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="email" class="form-label fw-semibold">{{ __('Email') }} <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}" required>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="password" class="form-label fw-semibold">{{ __('Password') }}</label>
                                <input type="password" name="password" id="password" class="form-control" title="- Password must be at least 10 characters in length - Password must contain at least one lowercase letter - Password must contain at least one uppercase letter - Password must contain at least one digit - Password must contain a special character">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="confirm-password" class="form-label fw-semibold">{{ __('Confirm Password') }}</label>
                                <input type="password" name="password_confirmation" id="confirm-password" class="form-control">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="gender" class="form-label fw-semibold">{{ __('Gender') }} <span class="text-danger">*</span></label>
                                <select name="gender" id="gender" class="form-select" required>
                                    <option value="">{{ __('Please select') }}</option>
                                    <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>{{ __('Male') }}</option>
                                    <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>{{ __('Female') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="country" class="form-label fw-semibold">{{ __('Country') }} <span class="text-danger">*</span></label>
                                @if(count($countries) > 0)
                                    <select name="country" id="country" class="form-select" required>
                                        <option value="">{{ __('Please Select') }}</option>
                                        @foreach($countries as $country)
                                            <option value="{{ $country['name'] }}" {{ $country['name'] == old('country') ? 'selected' : '' }}>{{ $country['name'] }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="text" name="country" id="country" class="form-control" value="{{ old('country') }}">
                                @endif
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="city" class="form-label fw-semibold">{{ __('City') }}</label>
                                <input type="text" name="city" id="city" class="form-control" value="{{ old('city') }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="address" class="form-label fw-semibold">{{ __('Address') }}</label>
                                <input type="text" name="address" id="address" class="form-control" value="{{ old('address') }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="phone" class="form-label fw-semibold">{{ __('Phone') }}</label>
                                <input type="text" name="phone" id="phone" class="form-control" value="{{ old('phone') }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="whatsapp" class="form-label fw-semibold">{{ __('Whatsapp') }}</label>
                                <input type="text" name="whatsapp" id="whatsapp" class="form-control" value="{{ old('whatsapp') }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="birthdate" class="form-label fw-semibold">{{ __('Birthdate') }}</label>
                                <input type="date" name="birthdate" id="birthdate" max="2005-12-31" class="form-control" value="{{ old('birthdate') }}">
                            </div>
                            <div class="col-12">
                                <label for="biography" class="form-label fw-semibold">{{ __('Biography') }}</label>
                                <textarea name="biography" id="biography" class="form-control" rows="3" placeholder="{{ __('Biography') }}">{{ old('biography') }}</textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">{{ __('Social media') }}</label>
                                @if(count($social_medias) > 0)
                                    @foreach($social_medias as $social_media)
                                        <div class="input-group mb-3">
                                            <input class="form-control" name="social_medias[][name]" type="text" value="{{ $social_media->name }}" placeholder="{{ __('Name') }}">
                                            <input class="form-control" name="social_medias[][url]" type="url" value="{{ $social_media->url }}" placeholder="{{ __('URL') }}">
                                        </div>
                                    @endforeach
                                @else
                                    <p class="text-muted small mb-0">{{ __('No social media entries yet.') }}</p>
                                @endif
                            </div>
                            <div class="col-12">
                                <h6 class="fw-semibold mb-3">{{ __('Select products that you provide') }}</h6>
                                <div class="element" id="div_1">
                                    <div class="row g-2 align-items-end mb-3">
                                        <div class="col-md-4">
                                            <select name="category" class="form-select category" id="category" data-id="1">
                                                <option value="">{{ __('Select Category') }}</option>
                                                @if(count($categories) > 0)
                                                    @foreach($categories as $category)
                                                        <option value="{{ $category->id }}" {{ $category->id == old('category') ? 'selected' : '' }}>{{ $category->name }}</option>
                                                    @endforeach
                                                @endif
                                            </select>
                                        </div>
                                        @if(count($selected_products) > 0)
                                        <div class="col-md-6 products_holder_1">
                                        @else
                                        <div class="col-md-6 products_holder_1" style="display:none;">
                                        @endif
                                            <label for="products" class="form-label small">{{ __('Products') }}</label>
                                            <div class="products_1">
                                                @if(count($selected_products) > 0)
                                                    @php($products = App\Models\Product::where('category_id', $selected_category)->get())
                                                    @foreach($products as $product)
                                                        <div class="form-check d-inline-block me-3">
                                                            <input type="checkbox" {{ in_array($product->id, $selected_products[$selected_category] ?? []) ? 'checked' : '' }} name="products[{{ $selected_category }}][{{ $product->id }}]" class="form-check-input" id="product-{{ $product->id }}" value="{{ $product->id }}" />
                                                            <label class="form-check-label" for="product-{{ $product->id }}">{{ $product->name }}</label>
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="add btn btn-primary btn-sm"><i class="las la-plus me-1"></i>{{ __('Add Product') }}</button>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="photo" class="form-label fw-semibold">{{ __('Photo') }}</label>
                                <input type="file" name="photo" id="photo" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">{{ __('Account type') }} <span class="text-danger">*</span></label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="account_type" value="merchant" id="account_merchant" {{ old('account_type', 'merchant') === 'merchant' ? 'checked' : '' }} required />
                                        <label class="form-check-label" for="account_merchant">{{ __('Merchant') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="account_type" value="supplier" id="account_supplier" {{ old('account_type') === 'supplier' ? 'checked' : '' }} required />
                                        <label class="form-check-label" for="account_supplier">{{ __('Supplier') }}</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input name="active" class="form-check-input" type="checkbox" value="1" id="active" {{ old('active') == 1 ? 'checked' : '' }}>
                                    <label class="form-check-label" for="active">{{ __('Deactivate your account') }}</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input name="private" class="form-check-input" type="checkbox" value="1" id="private" {{ old('private') == 1 ? 'checked' : '' }}>
                                    <label class="form-check-label" for="private">{{ __('Keep my information private') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Create User') }}
                            </button>
                            <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <x-slot name="script">
    <script src="{{ asset('rizz/libs/formrepeater/formrepeater.bundle.js') }}"></script>
    <script src="{{ asset('rizz/js/apps/save-product.js') }}"></script>
    <script type="text/javascript">
        $(document).ready(function() {
            $(".add").click(function(){
                var total_element = $(".element").length;
                var lastid = $(".element:last").attr("id");
                var split_id = lastid.split("_");
                var nextindex = Number(split_id[1]) + 1;
                var max = 5;
                if(total_element < max){
                    $(".element:first").before("<div class='element' id='div_"+ nextindex +"'></div>");
                    $("#div_" + nextindex).append('<div class="row g-2 align-items-end mb-3"><div class="col-md-4"><select class="form-select category" name="category" data-id="'+ nextindex +'"><option value="">{{ __("Select Category") }}</option>@if(count($categories) > 0) @foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach @endif</select></div><div class="col-md-6 products_holder_'+ nextindex +'" style="display:none;"><label class="form-label small">{{ __("Products") }}</label><div class="products_'+ nextindex +'"></div></div></div><button type="button" class="btn btn-light-danger btn-sm remove" id="remove_' + nextindex + '"><i class="las la-times"></i></button>');
                }
            });
            $(document).on('click','.remove',function(){
                var id = this.id;
                var split_id = id.split("_");
                var deleteindex = split_id[1];
                $("#div_" + deleteindex).remove();
            });
            $('#deletePhoto').on('click', function(e) {
                e.preventDefault();
                $('#deletePhoto').html('<i class="las la-spinner la-spin" style="font-size: 20px;"></i>');
                $.ajax({
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    url: '{{ route('profile.photo.delete') }}',
                    type: "delete",
                    data: { _token: '{{ csrf_token() }}' },
                    success:function(response) {
                        if(response.code == 200) {
                            setTimeout(function() {
                                if (typeof Swal !== 'undefined') {
                                    Swal.fire({ text: response.message, icon: response.status, buttonsStyling: false, confirmButtonText: "Ok, got it!", customClass: { confirmButton: "btn btn-primary" } });
                                }
                                $('#deletePhoto').fadeOut();
                                $('#userPhoto').attr('src', '{{ placeholder_image("avatar") }}');
                            }, 1000);
                        }
                    }
                });
            });
            $('.category').on('change', function() {
                var index = $(this).attr('data-id');
                $('.products_holder_' + index).hide();
                var categoryID = $(this).val();
                if(categoryID) {
                    $.ajax({
                        url: '{{ url('getmultiproducts') }}/' + encodeURIComponent(categoryID),
                        type: "GET",
                        dataType: "json",
                        success:function(data) {
                            if(data && data !== "") {
                                $('.products_holder_' + index).show();
                                $('.products_' + index).html(data.products);
                            }
                        }
                    });
                } else {
                    $('.products_holder_' + index).hide();
                    $('.products_' + index).html('');
                }
            });
        });
    </script>
    </x-slot>
</x-default-layout>
