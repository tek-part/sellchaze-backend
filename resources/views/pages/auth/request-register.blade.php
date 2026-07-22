<x-auth-layout>
    @if($errors->any())
        <div class="alert alert-danger mb-4">{{ $errors->first() }}</div>
    @endif

    <form method="POST" class="my-4" action="{{ route('register') }}">
        @csrf
        <input type="hidden" name="invitation_token" value="{{ $invitationToken }}">
        @if(!empty($legacyPermissionsQuery))
            <input type="hidden" name="permissions" value="{{ $legacyPermissionsQuery }}">
        @endif

        <div class="text-center mb-4">
            <h4 class="fw-bold mb-2">{{ __('Complete Registration') }}</h4>
            @if(!empty($isReusableInvite ?? false))
                <p class="text-muted small">{{ __('You are joining via a shared invitation link. Enter your own email below.') }}</p>
            @endif
            @if(!empty($inviteCode))
                <p class="text-muted small">{{ __('Invite code') }}: <span class="fw-bold text-primary">{{ $inviteCode }}</span></p>
            @endif
        </div>

        <div class="mb-3">
            <label class="form-label" for="name">{{ __('Name') }}</label>
            <input type="text" id="name" name="name" placeholder="{{ __('Name') }}" value="{{ old('name') }}" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="email">{{ __('Email') }}</label>
            @if(!empty($isReusableInvite ?? false))
                <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="{{ __('Email') }}" required autocomplete="email">
            @else
                <input type="email" class="form-control bg-light" value="{{ $email }}" disabled readonly>
                <input type="hidden" name="email" value="{{ $email }}">
            @endif
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">{{ __('Password') }}</label>
            <input type="password" id="password" name="password" placeholder="{{ __('Password') }}" class="form-control" required>
            <div class="form-text">{{ __('Use 8 or more characters with a mix of letters, numbers & symbols.') }}</div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="password_confirmation">{{ __('Repeat Password') }}</label>
            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="{{ __('Repeat Password') }}" class="form-control" required>
        </div>

        <div class="mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="toc" id="toc" value="1" required>
                <label class="form-check-label" for="toc">{{ __('I Accept the') }} <a href="#" class="text-primary">{{ __('Terms') }}</a></label>
            </div>
        </div>

        <div class="d-grid mb-4">
            <button type="submit" class="btn btn-primary">
                {{ __('Sign Up') }} <i class="las la-user-plus ms-1"></i>
            </button>
        </div>

        <div class="text-muted text-center fw-semibold small">
            {{ __('Already have an Account?') }}
            <a href="{{ route('login') }}" class="text-primary">{{ __('Sign in') }}</a>
        </div>
    </form>
</x-auth-layout>
