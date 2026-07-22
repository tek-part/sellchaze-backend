<x-auth-layout>
    <form method="POST" class="my-4" action="{{ route('storeInvitation') }}">
        @csrf

        @if($errors->any())
            <div class="alert alert-danger mb-4">{{ $errors->first() }}</div>
        @endif

        <div class="text-center mb-4">
            <h4 class="fw-bold mb-2">{{ __('Send invitation') }}</h4>
            <p class="text-muted small">{{ __('Invite a merchant or supplier by email. They will receive a link and an invite code.') }}</p>
            @if(auth()->user()->isMerchant() || auth()->user()->isSupplier())
                <p class="text-muted small">{{ __('Merchants and suppliers also have one reusable link and code on the Invitations page.') }}</p>
            @endif
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">{{ __('Email') }}</label>
            <input type="email" id="email" name="email" placeholder="{{ __('Email') }}" value="{{ old('email') }}" class="form-control" required>
        </div>

        @if(auth()->user()->hasRole('Admin'))
            <div class="mb-4">
                <label class="form-label">{{ __('Invite as') }}</label>
                <div class="d-flex flex-column gap-2 mt-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="invited_role" value="merchant" id="role_merchant" {{ old('invited_role', 'merchant') === 'merchant' ? 'checked' : '' }} required>
                        <label class="form-check-label" for="role_merchant">{{ __('Merchant') }}</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="invited_role" value="supplier" id="role_supplier" {{ old('invited_role') === 'supplier' ? 'checked' : '' }} required>
                        <label class="form-check-label" for="role_supplier">{{ __('Supplier') }}</label>
                    </div>
                </div>
            </div>
        @endif

        <div class="d-grid mb-4">
            <button type="submit" class="btn btn-primary">
                {{ __('Send invitation') }} <i class="las la-paper-plane ms-1"></i>
            </button>
        </div>
    </form>

    <div class="border-top pt-4 mt-4">
        <p class="text-muted fw-semibold text-center mb-3">{{ __('Have an invite code?') }}</p>
        <form method="get" action="{{ url('/register/invitation') }}" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch justify-content-center">
            <input type="text" name="code" class="form-control" placeholder="{{ __('Invite code') }}" maxlength="16" autocomplete="off">
            <button type="submit" class="btn btn-primary">{{ __('Continue') }}</button>
        </form>
    </div>
</x-auth-layout>
