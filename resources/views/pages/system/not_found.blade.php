<h1 class="fw-bolder fs-2 mb-4">
    {{ __('Oops!') }}
</h1>
<div class="fw-semibold fs-6 text-muted mb-4">
    {{ __("We can't find that page.") }}
</div>
@if (file_exists(public_path('rizz/images/extra/error.svg')))
<div class="mb-4">
    <img src="{{ asset('rizz/images/extra/error.svg') }}" class="mw-100" style="max-height: 200px;" alt="404">
</div>
@endif
<div class="mb-0">
    <a href="{{ url('/') }}" class="btn btn-primary">{{ __('Return Home') }}</a>
    @auth
    <a href="{{ route('dashboard') }}" class="btn btn-outline-primary ms-2">{{ __('Dashboard') }}</a>
    @endauth
</div>
