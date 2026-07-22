<x-auth-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold mb-2">{{ __('Complete your profile') }}</h4>
        <p class="text-muted mb-0 fs-6">{{ __('Please provide the required information to continue.') }}</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show mb-4">
            <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form action="{{ route('profile.complete.store') }}" method="POST" class="my-2">
        @csrf
        <div class="mb-3">
            <label for="company" class="form-label">{{ __('Company') }} <span class="text-danger">*</span></label>
            <input type="text" name="company" id="company" class="form-control" value="{{ old('company', $user->profile->company ?? '') }}" placeholder="{{ __('Company name') }}" required>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">{{ __('Phone') }} <span class="text-danger">*</span></label>
            <input type="text" name="phone" id="phone" class="form-control" value="{{ old('phone', $user->profile->phone ?? '') }}" placeholder="+1234567890" required>
        </div>
        <div class="mb-3">
            <label for="country" class="form-label">{{ __('Country') }} <span class="text-danger">*</span></label>
            @if(count($countries ?? []) > 0)
                <select name="country" id="country" class="form-select" required>
                    <option value="">{{ __('Please select') }}</option>
                    @foreach($countries as $country)
                        <option value="{{ $country['name'] }}" {{ ($country['name'] ?? '') == old('country', $user->profile->country ?? '') ? 'selected' : '' }}>{{ $country['name'] }}</option>
                    @endforeach
                </select>
            @else
                <input type="text" name="country" id="country" class="form-control" value="{{ old('country', $user->profile->country ?? '') }}" required>
            @endif
        </div>
        <div class="mb-3">
            <label for="city" class="form-label">{{ __('City') }}</label>
            <input type="text" name="city" id="city" class="form-control" value="{{ old('city', $user->profile->city ?? '') }}" placeholder="{{ __('City') }}">
        </div>
        <div class="mb-4">
            <label for="address" class="form-label">{{ __('Address') }}</label>
            <input type="text" name="address" id="address" class="form-control" value="{{ old('address', $user->profile->address ?? '') }}" placeholder="{{ __('Street address') }}">
        </div>
        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary">
                {{ __('Continue') }} <i class="las la-arrow-left ms-1"></i>
            </button>
        </div>
    </form>
</x-auth-layout>
