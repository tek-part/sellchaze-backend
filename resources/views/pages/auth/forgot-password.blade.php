<x-auth-layout
    body-class="forgot-password"
    page-css="forgot-password.css"
    :form-title="__('Forgot Password')"
    :form-subtitle="__('Enter the email address you used when you joined and we\'ll send you instructions to reset your password.')"
>
    <form method="POST" action="{{ route('password.email') }}" class="form-wrap">
        @csrf
        @if ($errors->any())
            <div class="text-danger text-s-regular mb-3">{{ $errors->first() }}</div>
        @endif
        <div class="form__wrap">
            <div class="form-group form-group__email">
                <label for="email" class="items__label">{{ __('Email') }}</label>
                <div class="items__inputs">
                    <input type="email" id="email" name="email" class="items__inputs--input" placeholder="{{ __('Enter your email') }}" value="{{ old('email') }}" required autocomplete="email">
                </div>
            </div>
            <div class="btn-wrap" style="width: 100%">
                <button type="submit" class="btn btn--medium btn--primary" style="width: 100%">{{ __('Send') }}</button>
            </div>
        </div>
        <p class="text-s-regular mt-3"><a href="{{ route('login') }}" class="link text-s-bold">{{ __('Back to Sign In') }}</a></p>
    </form>
</x-auth-layout>
