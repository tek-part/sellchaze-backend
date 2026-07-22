<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Email settings') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('Configure outgoing mail server.') }}</p>
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
                            <h6 class="mb-1">{{ __('Incoming server (reference)') }}</h6>
                            <p class="mb-0 small">{{ __('Server') }}: mail.wigpleasure.com &nbsp;|&nbsp; IMAP {{ __('Port') }}: 993 &nbsp;|&nbsp; POP3 {{ __('Port') }}: 995</p>
                        </div>
                    </div>

                    <form action="{{ route('settings.updateEmail') }}" method="POST">
                        @csrf
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="mail_mailer" class="form-label fw-semibold">{{ __('Mail driver') }}</label>
                                <select name="mail_mailer" id="mail_mailer" class="form-select">
                                    <option value="smtp" {{ old('mail_mailer', $settings['mail_mailer'] ?? 'smtp') === 'smtp' ? 'selected' : '' }}>SMTP</option>
                                    <option value="log" {{ old('mail_mailer', $settings['mail_mailer'] ?? '') === 'log' ? 'selected' : '' }}>{{ __('Log (no send)') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_host" class="form-label fw-semibold">{{ __('Outgoing server (SMTP host)') }}</label>
                                <input type="text" name="mail_host" id="mail_host" class="form-control" value="{{ old('mail_host', $settings['mail_host'] ?? 'mail.wigpleasure.com') }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_port" class="form-label fw-semibold">{{ __('SMTP port') }}</label>
                                <input type="text" name="mail_port" id="mail_port" class="form-control" value="{{ old('mail_port', $settings['mail_port'] ?? '465') }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_encryption" class="form-label fw-semibold">{{ __('Encryption') }}</label>
                                <select name="mail_encryption" id="mail_encryption" class="form-select">
                                    <option value="ssl" {{ old('mail_encryption', $settings['mail_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' }}>SSL</option>
                                    <option value="tls" {{ old('mail_encryption', $settings['mail_encryption'] ?? '') === 'tls' ? 'selected' : '' }}>TLS</option>
                                    <option value="none" {{ old('mail_encryption', $settings['mail_encryption'] ?? '') === 'none' ? 'selected' : '' }}>{{ __('None') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_username" class="form-label fw-semibold">{{ __('Username') }}</label>
                                <input type="text" name="mail_username" id="mail_username" class="form-control" value="{{ old('mail_username', $settings['mail_username'] ?? 'sellchase@wigpleasure.com') }}" placeholder="sellchase@wigpleasure.com">
                            </div>
                            <div class="col-md-6">
                                <label for="mail_password" class="form-label fw-semibold">{{ __('Password') }}</label>
                                <input type="password" name="mail_password" id="mail_password" class="form-control" placeholder="{{ __('Leave blank to keep current') }}" autocomplete="new-password">
                                <div class="form-text">{{ __('Use the email account password. Leave empty to keep existing.') }}</div>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_from_address" class="form-label fw-semibold">{{ __('From address') }}</label>
                                <input type="email" name="mail_from_address" id="mail_from_address" class="form-control" value="{{ old('mail_from_address', $settings['mail_from_address'] ?? '') }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="mail_from_name" class="form-label fw-semibold">{{ __('From name') }}</label>
                                <input type="text" name="mail_from_name" id="mail_from_name" class="form-control" value="{{ old('mail_from_name', $settings['mail_from_name'] ?? config('app.name')) }}">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">
                                <i class="las la-check me-1"></i> {{ __('Save') }}
                            </button>
                            <a href="{{ url('/') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>

                    <hr class="my-5">
                    <h6 class="fw-semibold mb-3">{{ __('Send test email') }}</h6>
                    <form action="{{ route('settings.email.test') }}" method="POST">
                        @csrf
                        <div class="d-flex flex-wrap gap-2 align-items-end">
                            <div class="flex-grow-1" style="min-width: 200px;">
                                <label for="test_email" class="form-label fw-semibold">{{ __('Email address') }}</label>
                                <input type="email" name="test_email" id="test_email" class="form-control" value="{{ old('test_email', auth()->user()->email ?? '') }}" placeholder="email@example.com" required>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="las la-paper-plane me-1"></i> {{ __('Send test email') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
