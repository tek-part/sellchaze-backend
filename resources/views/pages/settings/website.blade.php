<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Website settings') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Site title, description and logo for the external website.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('settings.updateWebsite') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-8 col-lg-6">
                                <label for="site_title" class="form-label fw-semibold">{{ __('Site title') }}</label>
                                <input type="text" name="site_title" id="site_title" class="form-control" value="{{ old('site_title', $settings['site_title'] ?? config('app.name')) }}" placeholder="{{ config('app.name') }}">
                                <div class="form-text">{{ __('Used in page titles and header.') }}</div>
                            </div>
                            <div class="col-12">
                                <label for="site_description" class="form-label fw-semibold">{{ __('Site description') }}</label>
                                <textarea name="site_description" id="site_description" class="form-control" rows="3" maxlength="500" placeholder="{{ __('Brief description for SEO.') }}">{{ old('site_description', $settings['site_description'] ?? '') }}</textarea>
                                <div class="form-text">{{ __('Used in meta description for SEO. Max 500 characters.') }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">{{ __('Site logo') }}</label>
                                @if (!empty($settings['site_logo']))
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <img src="{{ asset('storage/' . $settings['site_logo']) }}" alt="Logo" style="max-height: 60px;">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo" value="1">
                                            <label class="form-check-label" for="remove_logo">{{ __('Remove logo') }}</label>
                                        </div>
                                    </div>
                                @endif
                                <input type="file" name="site_logo" id="site_logo" class="form-control" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp">
                                <div class="form-text">{{ __('Logo for header and auth pages. JPG, PNG, WebP. Max 2MB.') }}</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Save') }}
                            </button>
                            <a href="{{ url('/') }}" class="btn btn-light" target="_blank">{{ __('View website') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
