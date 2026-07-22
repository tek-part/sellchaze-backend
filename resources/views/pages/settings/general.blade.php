<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('General settings') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Application name and basic settings.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('settings.updateGeneral') }}" method="POST">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="app_name" class="form-label fw-semibold">{{ __('App name') }}</label>
                                <input type="text" name="app_name" id="app_name" class="form-control" value="{{ old('app_name', $app_name ?? config('app.name')) }}" placeholder="{{ config('app.name') }}">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Save') }}
                            </button>
                            <a href="{{ url('/') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
