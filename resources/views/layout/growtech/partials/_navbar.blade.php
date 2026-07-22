@php
    $gt = asset('growtech/assets');
    $navbarClass = $navbarFullScreen ?? false ? 'navbar js-navbar navbar--full-screen' : 'navbar js-navbar';
@endphp
<header class="{{ $navbarClass }}">
    <div class="container">
        <div class="navbar__title">
            <a href="{{ url('/') }}" class="navbar__title-link">
                <img src="{{ asset('logo.png') }}" alt="{{ $siteTitle ?? config('app.name', 'Growtech') }}" class="navbar__logo" height="80" width="129">
            </a>
        </div>
        <div class="navbar__links">
            <a class="navbar__links__link {{ request()->routeIs('landing') ? 'active' : '' }}" href="{{ url('/') }}">{{ __('Home') }}</a>
            @if(Route::has('growtech.about'))
            <a class="navbar__links__link {{ request()->routeIs('growtech.about') ? 'active' : '' }}" href="{{ route('growtech.about') }}">{{ __('About') }}</a>
            @endif
            @if(Route::has('blog.index'))
            <a class="navbar__links__link {{ request()->routeIs('blog.*') ? 'active' : '' }}" href="{{ route('blog.index') }}">{{ __('Blog') }}</a>
            @endif
            @if(Route::has('growtech.contact'))
            <a class="navbar__links__link {{ request()->routeIs('growtech.contact') ? 'active' : '' }}" href="{{ route('growtech.contact') }}">{{ __('Contact') }}</a>
            @endif
            @if(Route::has('locale.switch'))
                @if (!session()->has('locale') || session('locale') === 'en')
                <a class="navbar__links__link" href="{{ route('locale.switch', ['locale' => 'ar']) }}">العربية</a>
                @else
                <a class="navbar__links__link" href="{{ route('locale.switch', ['locale' => 'en']) }}">English</a>
                @endif
            @endif
        </div>
        <div class="navbar__sign">
            <a class="btn btn--small btn--transparent-white" href="{{ route('login') }}">{{ __('Sign In') }}</a>
            @if (Route::has('register'))
            <a class="btn btn--small btn--secondary-white" href="{{ route('register.choose-type') }}">{{ __('Sign up') }}</a>
            @else
            <a class="btn btn--small btn--secondary-white" href="{{ route('requestInvitation') }}">{{ __('Request an invitation') }}</a>
            @endif
        </div>
        <div class="navbar__menu js-navbar__menu"></div>
        <div class="navbar__close js-navbar__close"></div>
    </div>
</header>
