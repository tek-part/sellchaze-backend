<x-auth-layout
    body-class="reset-password"
    page-css="reset-password.css"
    :form-title="__('Reset Password')"
    :form-subtitle="__('Your new password must be different from previous used passwords')"
>
    <form method="POST" action="{{ route('password.update') }}" class="form-wrap">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        @if ($errors->any())
            <div class="text-danger text-s-regular mb-3">{{ $errors->first() }}</div>
        @endif
        <div class="form__wrap">
            <div class="form-input">
                <div class="form-group form-group__email">
                    <label for="email" class="items__label">{{ __('Email') }}</label>
                    <div class="items__inputs">
                        <input type="email" id="email" name="email" class="items__inputs--input" value="{{ old('email', $request->email) }}" required autofocus>
                    </div>
                </div>
                <div class="form-group form-group__password">
                    <label for="password" class="items__label">{{ __('New Password') }}</label>
                    <div class="items__inputs">
                        <input type="password" id="password" name="password" class="items__inputs--input" placeholder="{{ __('Enter a new password') }}" required>
                    </div>
                </div>
                <div class="form-group form-group__password">
                    <label for="password_confirmation" class="items__label">{{ __('Confirm Password') }}</label>
                    <div class="items__inputs">
                        <input type="password" id="password_confirmation" name="password_confirmation" class="items__inputs--input" placeholder="{{ __('Confirm your password') }}" required>
                    </div>
                </div>
            </div>
            <div class="btn-wrap" style="width: 100%">
                <button type="submit" class="btn btn--medium btn--primary" style="width: 100%">{{ __('Reset Password') }}</button>
                <p class="text-s-regular mt-2">{{ __('If you didn\'t request a password recovery, please ignore this.') }}</p>
            </div>
        </div>
    </form>
</x-auth-layout>
