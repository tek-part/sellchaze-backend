<x-auth-layout>
    @push('rizz-js')
    <script>
        (function() {
            var checkInterval = setInterval(function() {
                fetch('{{ route("pending-approval.status") }}', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.approved) {
                        clearInterval(checkInterval);
                        window.location.href = '{{ route("dashboard") }}';
                    }
                })
                .catch(function() {});
            }, 5000);
        })();
    </script>
    @endpush
    <div class="text-center mb-4">
        <div class="mb-4">
            <i class="las la-clock fs-1 text-warning"></i>
        </div>
        <h4 class="fw-bold mb-2">{{ __('Account Pending Approval') }}</h4>
        <p class="text-muted mb-0 fs-6">{{ __('Your account has been created successfully. Please wait for an administrator to approve your account before you can access the dashboard.') }}</p>
    </div>

    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
        <i class="las la-info-circle me-3 fs-4"></i>
        <div>
            <p class="mb-0 small">{{ __('You will receive a notification once your account is approved. If you have any questions, please contact support.') }}</p>
        </div>
    </div>

    <div class="d-grid mb-4">
        <form action="{{ route('logout') }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-secondary w-100">
                {{ __('Sign out') }} <i class="las la-sign-out-alt ms-1"></i>
            </button>
        </form>
    </div>

    <div class="text-muted text-center fw-semibold small">
        {{ __('Need help?') }}
        <a href="{{ url('/') }}" class="text-primary">{{ __('Contact support') }}</a>
    </div>
</x-auth-layout>
