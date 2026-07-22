<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row justify-content-center">
        <div class="col-12">
            {{-- Top banner card (Rizz pages-profile style) --}}
            <div class="card">
								<div class="card-body">
                    <div class="row">
                        <div class="col-lg-4 align-self-center mb-3 mb-lg-0">
                            <div class="d-flex align-items-center flex-row flex-wrap">
                                <div class="position-relative me-3">
                                    @if($user->profile->photo)
                                        {!! user_photo($user->id, 'original', true, 'class="rounded-circle" id="userPhoto" style="width:120px;height:120px;object-fit:cover;"') !!}
                                    @else
                                        <img src="{{ placeholder_image('avatar') }}" alt="" class="rounded-circle" id="userPhoto" style="width:120px;height:120px;object-fit:cover;">
                                    @endif
                                    <label for="photo" class="thumb-md justify-content-center d-flex align-items-center bg-primary text-white rounded-circle position-absolute end-0 bottom-0 border border-3 border-white cursor-pointer" style="cursor:pointer;width:36px;height:36px;">
                                        <i class="las la-camera"></i>
                                    </label>
                                </div>
                                <div>
                                    <h5 class="fw-semibold fs-5 mb-1">{{ $user->name }}</h5>
                                    <p class="mb-0 text-muted fw-medium">{{ '@' . ($user->profile->username ?? '—') }}</p>
                                    @if($user->profile->company)
                                        <p class="mb-0 text-muted small">{{ $user->profile->company }}</p>
                                    @endif
                                    @if($user->profile->country || $user->profile->city)
                                        <p class="mb-0 text-muted small mt-1">
                                            <i class="las la-map-marker-alt me-1"></i>
                                            {{ trim(($user->profile->city ?? '') . ', ' . ($user->profile->country ?? ''), ', ') ?: '—' }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 ms-auto align-self-center">
                            @if($user->profile->photo)
                                <button type="button" id="deletePhoto" class="btn btn-light-danger btn-sm">
                                    <i class="las la-trash-alt me-1"></i> {{ __('Delete photo') }}
                                </button>
                            @endif
                            @if(auth()->user()->hasRole('Admin'))
                                <a href="{{ route('profile.get.assign.products', encrypt($user->id)) }}" class="btn btn-primary btn-sm ms-2">
                                    <i class="las la-box me-1"></i> {{ __('Assign Products') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row justify-content-center mt-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h4 class="card-title">{{ __('Personal Information') }}</h4>
                                </div>
                                <div class="col-auto">
                                    <a href="#profile-form" class="text-primary text-decoration-underline small"><i class="las la-pen me-1"></i>{{ __('Edit') }}</a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body pt-0">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2"><i class="las la-user me-2 text-secondary align-middle"></i> <b>{{ __('Name') }}</b>: {{ $user->name ?? '—' }}</li>
                                <li class="mb-2"><i class="las la-envelope me-2 text-secondary align-middle"></i> <b>{{ __('Email') }}</b>: {{ $user->email ?? '—' }}</li>
                                <li class="mb-2"><i class="las la-venus-mars me-2 text-secondary align-middle"></i> <b>{{ __('Gender') }}</b>: {{ ($user->profile->gender ?? '') == 'female' ? __('Female') : __('Male') }}</li>
                                @if($user->profile->birthdate)
                                <li class="mb-2"><i class="las la-birthday-cake me-2 text-secondary align-middle"></i> <b>{{ __('Birthdate') }}</b>: {{ $user->profile->birthdate }}</li>
                                @endif
                                @if($user->profile->phone)
                                <li class="mb-2"><i class="las la-phone me-2 text-secondary align-middle"></i> <b>{{ __('Phone') }}</b>: {{ $user->profile->phone }}</li>
                                @endif
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link fw-medium active" data-bs-toggle="tab" href="#settings" role="tab" aria-selected="true">{{ __('Settings') }}</a>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane active" id="settings" role="tabpanel">
                            <form action="{{ route('profile.update', $user->profile->username) }}" method="POST" enctype="multipart/form-data" id="profile-form">
										@csrf

										@if ($errors->any())
                                    <div class="alert alert-danger alert-dismissible fade show mb-4">
                                        <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
											</div>
										@endif

                                <input type="file" name="photo" id="photo" class="d-none" accept=".jpg,.jpeg,.png,.webp" onchange="if(this.files.length) this.form.submit();">

                                {{-- Account --}}
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Account details') }}</h4></div>
                                    <div class="card-body pt-0">
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Username') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="text" name="username" class="form-control" value="{{ old('username', $user->profile->username ?? '') }}" placeholder="@username">
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Name') }} <span class="text-danger">*</span></label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="text" name="name" class="form-control" value="{{ old('name', $user->name ?? '') }}" required>
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Email') }} <span class="text-danger">*</span></label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="email" name="email" class="form-control" value="{{ old('email', $user->email ?? '') }}" required>
                                            </div>
                                        </div>
                                        <div class="mb-0 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Password') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="password" name="password" class="form-control" placeholder="{{ __('Leave blank to keep current') }}" autocomplete="new-password">
                                                <span class="form-text">{{ __('Use 8 or more characters.') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                @if(auth()->id() == $user->id)
                                {{-- API Key (own profile only) --}}
                                <div class="card mb-4" id="api-key-card">
                                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <h4 class="card-title mb-0">{{ __('API Key') }}</h4>
                                        <div class="d-flex gap-2">
                                            @if($user->getRawApiKey())
                                                <button type="button" class="btn btn-sm btn-light-warning" id="regenerateApiKey" title="{{ __('Regenerate API key') }}">
                                                    <i class="las la-sync me-1"></i> {{ __('Regenerate') }}
                                                </button>
                                            @else
                                                <button type="button" class="btn btn-sm btn-primary" id="generateApiKey">
                                                    <i class="las la-key me-1"></i> {{ __('Generate API Key') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="card-body pt-0">
                                        @if($user->getRawApiKey())
                                            <div class="mb-2">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <code id="api-key-display" class="flex-grow-1 text-break" data-full-key="{{ $user->getRawApiKey() }}">{{ $user->getApiKeyForDisplay() }}</code>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary copy-api-key">
                                                        <i class="las la-copy me-1"></i> {{ __('Copy') }}
                                                    </button>
                                                </div>
                                            </div>
                                            <p class="form-text mb-0 small">{{ __('Use this key in SELLCHASE_API_KEY in your store .env file. Orders from your store will go to the suppliers you have added in Invitations.') }}</p>
                                        @else
                                            <p class="text-muted mb-2">{{ __('Generate an API key to connect your store to this dashboard.') }}</p>
                                            <p class="form-text mb-0 small">{{ __('After generating, use it in SELLCHASE_API_KEY in your store .env file. Add suppliers via Invitations so they receive orders from your store.') }}</p>
                                        @endif
                                    </div>
											</div>
										@endif

                                {{-- Personal --}}
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Personal information') }}</h4></div>
                                    <div class="card-body pt-0">
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Gender') }} <span class="text-danger">*</span></label>
                                            <div class="col-lg-9 col-xl-8">
                                                <select name="gender" class="form-select" required>
                                                    <option value="">{{ __('Please select') }}</option>
                                                    <option value="male" {{ old('gender', $user->profile->gender ?? '') == 'male' ? 'selected' : '' }}>{{ __('Male') }}</option>
                                                    <option value="female" {{ old('gender', $user->profile->gender ?? '') == 'female' ? 'selected' : '' }}>{{ __('Female') }}</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Birthdate') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="date" name="birthdate" class="form-control" max="2012-01-01" value="{{ old('birthdate', $user->profile->birthdate ?? '') }}">
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Company') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="text" name="company" class="form-control" value="{{ old('company', $user->profile->company ?? '') }}" placeholder="{{ __('Company name') }}">
                                            </div>
										</div>
                                        <div class="mb-0 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Biography') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <textarea name="biography" class="form-control" rows="4" placeholder="{{ __('Tell us about yourself') }}">{{ old('biography', $user->profile->biography ?? '') }}</textarea>
										</div>
										</div>
										</div>
										</div>

                                {{-- Location & Contact --}}
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Location & contact') }}</h4></div>
                                    <div class="card-body pt-0">
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Country') }} <span class="text-danger">*</span></label>
                                            <div class="col-lg-9 col-xl-8">
											@if(count($countries) > 0)
                                                    <select name="country" class="form-select" required>
                                                        <option value="">{{ __('Please select') }}</option>
														@foreach($countries as $country)
                                                            <option value="{{ $country['name'] }}" {{ $country['name'] == old('country', $user->profile->country ?? '') ? 'selected' : '' }}>{{ $country['name'] }}</option>
														@endforeach
													</select>
											@else
                                                    <input type="text" name="country" class="form-control" value="{{ old('country', $user->profile->country ?? '') }}">
											@endif
										</div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('City') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="text" name="city" class="form-control" value="{{ old('city', $user->profile->city ?? '') }}">
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Address') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <input type="text" name="address" class="form-control" value="{{ old('address', $user->profile->address ?? '') }}" placeholder="{{ __('Street address') }}">
                                            </div>
                                        </div>
                                        <div class="mb-3 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Phone') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="las la-phone"></i></span>
                                                    <input type="text" name="phone" class="form-control" value="{{ old('phone', $user->profile->phone ?? '') }}" placeholder="+1234567890">
                                                </div>
                                            </div>
										</div>
                                        <div class="mb-0 row">
                                            <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('WhatsApp') }}</label>
                                            <div class="col-lg-9 col-xl-8">
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="lab la-whatsapp"></i></span>
                                                    <input type="text" name="whatsapp" class="form-control" value="{{ old('whatsapp', $user->profile->whatsapp ?? '') }}" placeholder="+1234567890">
										</div>
										</div>
										</div>
										</div>
										</div>

                                {{-- Social media --}}
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Social media') }}</h4></div>
                                    <div class="card-body pt-0">
											@if(count($social_medias) > 0)
                                            @foreach($social_medias as $idx => $social_media)
                                                <div class="mb-3 row">
                                                    <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Platform') }}</label>
                                                    <div class="col-lg-9 col-xl-8">
                                                        <div class="input-group">
                                                            <input type="text" name="social_medias[][name]" class="form-control" placeholder="{{ __('Platform') }}" value="{{ is_object($social_media) ? ($social_media->name ?? '') : ($social_media['name'] ?? '') }}">
                                                            <input type="url" name="social_medias[][url]" class="form-control" placeholder="https://..." value="{{ is_object($social_media) ? ($social_media->url ?? '') : ($social_media['url'] ?? '') }}">
                                                        </div>
														</div>
													</div>
												@endforeach
											@else
                                            <div class="mb-3 row">
                                                <label class="col-xl-3 col-lg-3 col-form-label text-end">{{ __('Platform') }}</label>
                                                <div class="col-lg-9 col-xl-8">
                                                    <div class="input-group">
                                                        <input type="text" name="social_medias[][name]" class="form-control" placeholder="{{ __('Platform') }}">
                                                        <input type="url" name="social_medias[][url]" class="form-control" placeholder="https://...">
                                                    </div>
                                                </div>
                                            </div>
											@endif
										</div>
                                </div>

                                {{-- Assigned products (admin) --}}
                                @if(auth()->user()->hasRole('Admin') || $invitation !== null)
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Assigned products') }}</h4></div>
                                    <div class="card-body pt-0">
										@if(count($selected_products) > 0)
                                            <div class="mb-4">
                                                <span class="fw-semibold">{{ __('Current products') }}:</span>
                                                <ul class="mt-2 mb-0">
                                                    @foreach($selected_products as $catId => $productIds)
                                                        @php($category = App\Models\Category::find($catId))
                                                        @if($category)
                                                            <li class="mb-2">
                                                                <span class="fw-bold">{{ $category->name }}</span>
                                                                @php($products = App\Models\Product::where('category_id', $catId)->get())
                                                                <ul class="mt-1 text-muted small">
															@foreach($products as $product)
                                                                        @if(in_array($product->id, is_array($productIds) ? $productIds : []))
																	<li>{{ $product->name }}</li>
																@endif
															@endforeach
														</ul>
													</li>
                                                        @endif
												@endforeach
											</ul>
                                            </div>
										@endif
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">{{ __('Category') }}</label>
                                                <select class="form-select category" id="category" data-id="1">
                                                    <option value="">{{ __('Select category') }}</option>
																@foreach($categories as $category)
																	<option value="{{ $category->id }}">{{ $category->name }}</option>
																@endforeach
														</select>
													</div>
                                            <div class="col-md-8 mb-3 products_holder_1" style="{{ count($selected_products) > 0 ? '' : 'display:none;' }}">
                                                <label class="form-label d-block">{{ __('Products') }}</label>
                                                <div id="products-container">
                                                    @foreach($selected_products as $sCatId => $selectedProductIds)
                                                        @php($prods = App\Models\Product::where('category_id', $sCatId)->get())
                                                        @if($prods->isNotEmpty())
                                                        <div class="products products_{{ $sCatId }}" style="display:{{ (string)$sCatId === (string)($selected_category ?? '') ? 'block' : 'none' }};">
                                                            @foreach($prods as $product)
                                                                <div class="form-check mb-2">
                                                                    <input type="checkbox" name="products[{{ $sCatId }}][{{ $product->id }}]" class="form-check-input" id="product-{{ $product->id }}" value="{{ $product->id }}" {{ in_array($product->id, is_array($selectedProductIds) ? $selectedProductIds : []) ? 'checked' : '' }}>
																			<label class="form-check-label" for="product-{{ $product->id }}">{{ $product->name }}</label>
																		</div>
																	@endforeach
																</div>
                                                        @endif
															@endforeach
                                                </div>
													</div>
												</div>
											</div>
													</div>
													@endif

                                {{-- Privacy --}}
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Privacy & status') }}</h4></div>
                                    <div class="card-body pt-0">
                                        <div class="form-check form-switch mb-3">
                                            <input name="active" class="form-check-input" type="checkbox" value="1" id="active" {{ ($user->profile->active ?? 1) == 1 ? 'checked' : '' }}>
                                            <label class="form-check-label" for="active">{{ __('Account active') }}</label>
                                            <span class="form-text d-block">{{ __('Uncheck to deactivate your account') }}</span>
																			</div>
                                        <div class="form-check form-switch mb-0">
                                            <input name="private" class="form-check-input" type="checkbox" value="1" id="private" {{ ($user->profile->private ?? 0) == 1 ? 'checked' : '' }}>
                                            <label class="form-check-label" for="private">{{ __('Make my information private') }}</label>
                                            <span class="form-text d-block">{{ __('Hide profile from others') }}</span>
																	</div>
														</div>
													</div>

                                {{-- Permissions (admin) --}}
                                @if(auth()->user()->hasRole('Admin') && !empty(Request::segment(2)))
                                <div class="card mb-4">
                                    <div class="card-header"><h4 class="card-title mb-0">{{ __('Permissions') }}</h4></div>
                                    <div class="card-body pt-0">
                                        <div class="row g-3">
                                            @forelse ($permissions as $permission)
                                                <div class="col-6 col-md-4 col-lg-3">
                                                    <div class="form-check">
                                                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" class="form-check-input" id="perm-{{ $permission->id }}" {{ in_array($permission->id, $userHasPermissions ?? []) ? 'checked' : '' }}>
                                                        <label class="form-check-label" for="perm-{{ $permission->id }}">{{ $permission->name }}</label>
										</div>
													</div>
												@empty
                                                <p class="text-muted">{{ __('No permissions defined') }}</p>
												@endforelse
                                        </div>
											</div>
										</div>
										@endif

                                <div class="row">
                                    <div class="col-lg-9 col-xl-8 offset-lg-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="las la-check me-1"></i> {{ __('Update profile') }}
                                        </button>
                                        <a href="{{ url()->previous() }}" class="btn btn-light">{{ __('Cancel') }}</a>
										</div>
										</div>
									</form>
								</div>
							</div>
						</div>
			</div>
        </div>
    </div>

    <x-slot name="script">
    <script>
    document.addEventListener('DOMContentLoaded', function() {
			$('#deletePhoto').on('click', function(e) {
				e.preventDefault();
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="las la-spinner la-spin me-1"></i>{{ __("Deleting...") }}');
				$.ajax({
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                url: '{{ route("profile.photo.delete") }}',
                type: 'DELETE',
                data: { _token: '{{ csrf_token() }}' },
                success: function(response) {
                    if (response.code == 200) {
                        $('#userPhoto').attr('src', '{{ placeholder_image("avatar") }}');
                        btn.fadeOut();
                        if (typeof rizzToast === 'function') rizzToast('success', response.message);
                        else if (typeof Swal !== 'undefined') Swal.fire({ text: response.message, icon: 'success', confirmButtonText: 'OK', customClass: { confirmButton: 'btn btn-primary' } });
                        else alert(response.message);
                    } else {
                        btn.prop('disabled', false).html('<i class="las la-trash-alt me-1"></i>{{ __("Delete photo") }}');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('<i class="las la-trash-alt me-1"></i>{{ __("Delete photo") }}');
                }
            });
        });
        @if(auth()->id() == $user->id)
        $('.copy-api-key').on('click', function() {
            var el = document.getElementById('api-key-display');
            if (!el) return;
            var fullKey = el.getAttribute('data-full-key');
            if (!fullKey) return;
            navigator.clipboard.writeText(fullKey).then(function() {
                if (typeof rizzToast === 'function') rizzToast('success', '{{ __("API key copied to clipboard.") }}');
                else if (typeof toastr !== 'undefined') toastr.success('{{ __("API key copied to clipboard.") }}');
                else alert('{{ __("API key copied.") }}');
            });
        });
        function doGenerateApiKey() {
            var btn = $('#generateApiKey, #regenerateApiKey');
            var origHtml = btn.html();
            btn.prop('disabled', true).html('<i class="las la-spinner la-spin me-1"></i>{{ __("Processing...") }}');
            $.ajax({
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                url: '{{ route("profile.api-key.generate") }}',
                type: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                success: function(response) {
                    if (typeof rizzToast === 'function') rizzToast('success', response.message || '{{ __("API key generated successfully.") }}');
                    else if (typeof Swal !== 'undefined') Swal.fire({ text: response.message || '{{ __("API key generated successfully.") }}', icon: 'success' });
                    else alert(response.message || '{{ __("API key generated successfully.") }}');
                    if (response.api_key) setTimeout(function() { location.reload(); }, 800);
                },
                error: function(xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || '{{ __("Failed to generate API key.") }}';
                    if (typeof rizzToast === 'function') rizzToast('error', msg);
                    else alert(msg);
                    btn.prop('disabled', false).html(origHtml);
								}
							});
						}
        $('#generateApiKey, #regenerateApiKey').on('click', doGenerateApiKey);
        @endif

			$('.category').on('change', function() {
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
