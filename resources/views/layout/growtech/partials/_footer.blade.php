@php $gt = asset('growtech/assets'); @endphp
<footer class="footer footer--v1 footer-custom1">
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle-61.svg" alt="" class="footer--v1__img footer--v1__img--1"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle60.svg" alt="" class="footer--v1__img footer--v1__img--2"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle37.svg" alt="" class="footer--v1__img footer--v1__img--3"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/path.svg" alt="" class="footer--v1__img footer--v1__img--4"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle3126.svg" alt="" class="footer--v1__img footer--v1__img--5"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle-37.svg" alt="" class="footer--v1__img footer--v1__img--6"/>
    <img src="{{ $gt }}/media/images/patterns-and-ornaments/rectangle-37.svg" alt="" class="footer--v1__img footer--v1__img--7"/>
    <div class="footer__container container">
        <div class="footer__sosmed">
            <a href="{{ url('/') }}" class="footer__logo-link">
                <img src="{{ asset('logo.png') }}" alt="{{ $siteTitle ?? config('app.name', 'Growtech') }}" class="footer__logo" height="40" width="40">
            </a>
            <h3 class="footer__sosmed__title">{{ $siteTitle ?? config('app.name', 'Growtech') }}</h3>
            <p class="footer__sosmed__detail text-m-regular">{{ $siteDescription ?? __('landing.hero.subtitle') }}</p>
            <div class="footer__sosmed__flex">
                <button class="btn btn-box"><img src="{{ $gt }}/media/images/icons/globe.svg" alt="Logo" class="btn-box__box-icon"/></button>
                <button class="btn btn-box"><img src="{{ $gt }}/media/images/icons/facebook.svg" alt="Logo" class="btn-box__box-icon"/></button>
                <button class="btn btn-box"><img src="{{ $gt }}/media/images/icons/twitter.svg" alt="Logo" class="btn-box__box-icon"/></button>
                <button class="btn btn-box"><img src="{{ $gt }}/media/images/icons/google.svg" alt="Logo" class="btn-box__box-icon"/></button>
                <button class="btn btn-box"><img src="{{ $gt }}/media/images/icons/linkedin.svg" alt="Logo" class="btn-box__box-icon"/></button>
            </div>
        </div>
        <div class="footer__links">
            <div class="footer__links__container">
                <div class="footer__links__pages">
                    <span class="footer__links__pages__title text-m-bold">{{ __('Pages') }}</span>
                    <div class="footer__links__pages__links">
                        <a class="footer__links__pages__links__a" href="{{ url('/') }}">Home</a>
                        @if(Route::has('growtech.about'))
                        <a class="footer__links__pages__links__a" href="{{ route('growtech.about') }}">About</a>
                        @endif
                        @if(Route::has('blog.index'))
                        <a class="footer__links__pages__links__a" href="{{ route('blog.index') }}">Blog</a>
                        @endif
                        @if(Route::has('growtech.help-center'))
                        <a class="footer__links__pages__links__a" href="{{ route('growtech.help-center') }}">Help Center</a>
                        @endif
                        @if(Route::has('growtech.faq'))
                        <a class="footer__links__pages__links__a" href="{{ route('growtech.faq') }}">FAQ</a>
                        @endif
                        @if(Route::has('growtech.contact'))
                        <a class="footer__links__pages__links__a" href="{{ route('growtech.contact') }}">Contact</a>
                        @endif
                    </div>
                </div>
            </div>
            <div class="footer__links__pages">
                <span class="footer__links__pages__title text-m-bold">{{ __('Utility') }}</span>
                <div class="footer__links__pages__links">
                    <a class="footer__links__pages__links__a" href="{{ route('login') }}">{{ __('Sign In') }}</a>
                    @if(Route::has('register'))
                    <a class="footer__links__pages__links__a" href="{{ route('register.choose-type') }}">{{ __('Sign up') }}</a>
                    @endif
                    <a class="footer__links__pages__links__a" href="{{ route('password.request') }}">{{ __('Forgot password') }}</a>
                    @if(Route::has('growtech.changelog'))
                    <a class="footer__links__pages__links__a" href="{{ route('growtech.changelog') }}">Changelog</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="footer__copyright container">
        <p>&copy; {{ date('Y') }} {{ $siteTitle ?? config('app.name') }}. {{ __('B2B orders and quotations') }}</p>
    </div>
</footer>
