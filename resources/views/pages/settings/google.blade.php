<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Google OAuth settings') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Configure Google sign-in and sign-up. Leave empty to disable.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4">
                            <p class="mb-0">{{ session('success') }}</p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <p class="mb-0">{{ session('error') }}</p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <div class="alert alert-info d-flex align-items-center mb-4">
                        <i class="las la-info-circle me-3 fs-4"></i>
                        <div>
                            <h6 class="mb-1">{{ __('Redirect URI') }}</h6>
                            <p class="mb-0 small font-monospace">{{ $redirect_uri }}</p>
                            <p class="mb-0 small mt-1">{{ __('Use this URL when configuring your Google OAuth consent screen.') }}</p>
                        </div>
                    </div>

                    <form action="{{ route('settings.updateGoogle') }}" method="POST">
                        @csrf
                        <div class="row g-4">
                            <div class="col-12">
                                <label for="google_client_id" class="form-label fw-semibold">{{ __('Google Client ID') }}</label>
                                <input type="text" name="google_client_id" id="google_client_id" class="form-control" value="{{ old('google_client_id', $settings['google_client_id'] ?? '') }}" placeholder="{{ __('Client ID from Google Cloud Console') }}" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label for="google_client_secret" class="form-label fw-semibold">{{ __('Google Client Secret') }}</label>
                                <input type="password" name="google_client_secret" id="google_client_secret" class="form-control" value="{{ old('google_client_secret', $settings['google_client_secret'] ?? '') }}" placeholder="{{ __('Leave blank to keep current') }}" autocomplete="new-password">
                                <div class="form-text">{{ __('Use credentials from Google Cloud Console > APIs & Services > Credentials.') }}</div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="las la-save me-1"></i> {{ __('Save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
