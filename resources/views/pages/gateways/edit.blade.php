<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <x-crm.listing-wrap>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h4 class="card-title">{{ __('Edit Payment Gateway') }}</h4>
                                <p class="mb-0 text-muted small">{{ __('Update gateway details.') }}</p>
                            </div>
                            <div class="col-auto">
                                <a href="{{ route('gateways.index') }}" class="btn btn-light btn-sm">
                                    <i class="iconoir-arrow-left me-1"></i> {{ __('Back') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if(session()->has('error'))
                            <div class="alert alert-danger alert-dismissible fade show mb-4">{{ session()->get('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                        @endif
                        @if($errors->any())
                            <div class="alert alert-danger alert-dismissible fade show mb-4">
                                <ul class="mb-0 ps-3">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        <form action="{{ route('gateways.update', $gateway->slug) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label for="name" class="form-label fw-semibold">{{ __('Name') }} <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" value="{{ old('name', $gateway->name) }}" class="form-control" placeholder="{{ __('e.g. PayPal, Stripe, Cash') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="slug" class="form-label fw-semibold">{{ __('Slug') }}</label>
                                    <input type="text" name="slug" id="slug" value="{{ old('slug', $gateway->slug) }}" class="form-control" placeholder="{{ __('e.g. paypal, stripe, cod') }}">
                                    <small class="text-muted">{{ __('Must be unique. Changing may affect existing links.') }}</small>
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-4 pt-3 border-top">
                                <button type="submit" class="btn btn-primary">
                                    <i class="iconoir-check me-1"></i> {{ __('Update') }}
                                </button>
                                <a href="{{ route('gateways.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </x-crm.listing-wrap>
</x-default-layout>
