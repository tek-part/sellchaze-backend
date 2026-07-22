<x-auth-layout
    body-class="sign-in"
    page-css="sign-in.css"
    :form-title="__('Sign In to :name', ['name' => $siteTitle ?? config('app.name')])"
    :form-subtitle="__('Fill your email and password to sign in')"
>
    <form action="{{ route('login') }}" method="POST" class="form-wrap">
        @csrf
        <div class="form-input">
            <div class="form-group form-group__email">
                <label for="email" class="items__label">{{ __('Email') }}</label>
                <div class="items__inputs">
                    <input type="email" id="email" name="email" class="items__inputs--input" placeholder="{{ __('Enter your email') }}" value="{{ old('email') }}" required autocomplete="email">
                </div>
            </div>
            <div class="form-group form-group__password">
                <label for="password" class="items__label">{{ __('Password') }}</label>
                <div class="items__inputs">
                    <input type="password" id="password" name="password" class="items__inputs--input" placeholder="{{ __('Enter your password') }}" required autocomplete="current-password">
                </div>
            </div>
            <div class="form-option">
                <label class="items__checkbox text-s-medium">{{ __('Remember Me') }}
                    <input type="checkbox" name="remember" id="remember"/>
                    <span class="items__checkbox--checkmark"></span>
                </label>
                <a href="{{ route('password.request') }}" class="link"><p class="link__text text-s-medium">{{ __('Forgot your password?') }}</p></a>
            </div>
        </div>
        @if ($errors->any())
            <div class="text-danger text-s-regular mb-3">{{ $errors->first() }}</div>
        @endif
        <div class="form-btn">
            <div class="btn-wrap" style="width: 100%">
                <button type="submit" class="btn btn--medium btn--primary" style="width: 100%">{{ __('Sign In') }}</button>
            </div>
            <p class="text-s-regular">{{ __('Don\'t have an account?') }} <a class="link text-s-bold" href="{{ route('register.choose-type') }}">{{ __('Sign Up') }}</a></p>
        </div>
        @if(config('services.google.client_id'))
        <div class="form-separator">
            <div class="line-separator"></div>
            <p class="text-s-regular">{{ __('Or') }}</p>
            <div class="line-separator"></div>
        </div>
        <div class="form-footer">
            <a href="{{ route('auth.google') }}" class="btn btn-login" style="width: 100%">
                <svg width="24" height="24" viewBox="0 0 18 18"><path fill="#4285F4" d="M16.51 8H8.98v3h4.3c-.18 1-.74 1.48-1.6 2.04v2.01h2.6a7.8 7.8 0 0 0 2.38-5.88c0-.57-.05-.66-.15-1.18z"/><path fill="#34A853" d="M8.98 17c2.16 0 3.97-.72 5.3-1.94l-2.6-2a4.8 4.8 0 0 1-7.18-2.54H1.83v2.07A8 8 0 0 0 8.98 17z"/><path fill="#FBBC05" d="M4.5 10.52a4.8 4.8 0 0 1 0-3.04V5.41H1.83a8 8 0 0 0 0 7.18l2.67-2.07z"/><path fill="#EA4335" d="M8.98 4.18c1.17 0 2.23.4 3.06 1.2l2.3-2.3A8 8 0 0 0 1.83 5.4L4.5 7.49a4.77 4.77 0 0 1 4.48-3.3z"/></svg>
                <p class="btn-login__text text-m-bold">{{ __('Sign in with Google') }}</p>
            </a>
        </div>
        @endif
    </form>
</x-auth-layout>
