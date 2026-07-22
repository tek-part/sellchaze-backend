<x-auth-layout
    body-class="sign-up"
    page-css="sign-up.css"
    :form-title="__('Sign Up to :name', ['name' => $siteTitle ?? config('app.name')])"
    :form-subtitle="__('Create an account today and start using :name', ['name' => $siteTitle ?? config('app.name')])"
>
    <form action="{{ route('register') }}" method="POST" class="form-wrap">
        @csrf
        <input type="hidden" name="type" value="{{ $type ?? 'merchant' }}">
        @if ($errors->any())
            <div class="text-danger text-s-regular mb-3">{{ $errors->first() }}</div>
        @endif
        @if(isset($type) && $type)
            <div class="sign-up__account-row">
                <span class="sign-up__account-badge">{{ $type === 'merchant' ? __('Register as Merchant') : __('Register as Supplier') }}</span>
                <a href="{{ route('register.choose-type') }}" class="sign-up__change-type text-s-regular">{{ __('Change account type') }}</a>
            </div>
        @endif
        <div class="form-input">
            <div class="sign-up__form-row">
                <div class="form-group form-group__name">
                    <label for="name" class="items__label">{{ __('Name') }}</label>
                    <div class="items__inputs">
                        <input type="text" id="name" name="name" class="items__inputs--input" placeholder="{{ __('Enter your name') }}" value="{{ old('name') }}" required autocomplete="name">
                    </div>
                </div>
                <div class="form-group form-group__email">
                    <label for="email" class="items__label">{{ __('Email') }}</label>
                    <div class="items__inputs">
                        <input type="email" id="email" name="email" class="items__inputs--input" placeholder="{{ __('Enter your email') }}" value="{{ old('email') }}" required autocomplete="email">
                    </div>
                </div>
            </div>
            <div class="form-group form-group__password">
                <label for="password" class="items__label">{{ __('Password') }}</label>
                <div class="items__inputs">
                    <input type="password" id="password" name="password" class="items__inputs--input" placeholder="{{ __('Enter your password') }}" required autocomplete="new-password">
                </div>
                <p class="text-s-regular mt-1">{{ __('Use 8 or more characters with a mix of letters, numbers & symbols.') }}</p>
            </div>
            <div class="form-group form-group__password">
                <label for="password_confirmation" class="items__label">{{ __('Repeat Password') }}</label>
                <div class="items__inputs">
                    <input type="password" id="password_confirmation" name="password_confirmation" class="items__inputs--input" placeholder="{{ __('Repeat your password') }}" required>
                </div>
            </div>
            <div class="form-option">
                <label class="items__checkbox text-s-regular" style="text-transform: none">
                    {{ __('I have read and agree to the Terms & Conditions') }}
                    <input type="checkbox" name="toc" id="toc" value="1" required/>
                    <span class="items__checkbox--checkmark"></span>
                </label>
            </div>
        </div>
        <div class="form-btn">
            <div class="btn-wrap" style="width: 100%">
                <button type="submit" class="btn btn--medium btn--primary" style="width: 100%">{{ __('Sign Up') }}</button>
            </div>
            <p class="text-s-regular">{{ __('Already have an account?') }} <a class="link text-s-bold text-primary" href="{{ route('login') }}">{{ __('Sign In') }}</a></p>
        </div>
        @if(config('services.google.client_id'))
        <div class="form-separator">
            <div class="line-separator"></div>
            <p class="text-s-regular">{{ __('Or') }}</p>
            <div class="line-separator"></div>
        </div>
        <div class="form-footer">
            <a href="{{ route('auth.google', ['intent' => 'register', 'type' => $type ?? 'merchant']) }}" class="btn btn-login" style="width: 100%">
                <svg width="24" height="24" viewBox="0 0 18 18"><path fill="#4285F4" d="M16.51 8H8.98v3h4.3c-.18 1-.74 1.48-1.6 2.04v2.01h2.6a7.8 7.8 0 0 0 2.38-5.88c0-.57-.05-.66-.15-1.18z"/><path fill="#34A853" d="M8.98 17c2.16 0 3.97-.72 5.3-1.94l-2.6-2a4.8 4.8 0 0 1-7.18-2.54H1.83v2.07A8 8 0 0 0 8.98 17z"/><path fill="#FBBC05" d="M4.5 10.52a4.8 4.8 0 0 1 0-3.04V5.41H1.83a8 8 0 0 0 0 7.18l2.67-2.07z"/><path fill="#EA4335" d="M8.98 4.18c1.17 0 2.23.4 3.06 1.2l2.3-2.3A8 8 0 0 0 1.83 5.4L4.5 7.49a4.77 4.77 0 0 1 4.48-3.3z"/></svg>
                <p class="btn-login__text text-m-bold">{{ __('Sign up with Google') }}</p>
            </a>
        </div>
        @endif
    </form>
</x-auth-layout>
