<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>{{ $seoTitle ?? $title ?? config('app.name', 'Growtech') }}</title>
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-google')
    @else
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @endif
    <link rel="icon" type="image/png" href="{{ $siteFavicon ?? asset('icon.png') }}">
    <link href="{{ asset('growtech/assets/css/styles.bundle.css') }}" rel="stylesheet" type="text/css" />
    @if(!empty($pageCss))
    <link href="{{ asset('growtech/assets/css/pages/' . $pageCss) }}" rel="stylesheet" type="text/css" />
    @endif
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-typography')
    @endif
    @isset($seoMeta)
        @include('layout.growtech.partials._seo-meta', ['seoMeta' => $seoMeta])
    @endisset
    @isset($jsonLd)
        @include('layout.growtech.partials._json-ld', ['jsonLd' => $jsonLd])
    @endisset
    <style>.navbar__title-link{display:flex;align-items:center;text-decoration:none;color:inherit}.navbar__title-link:hover{color:inherit}.navbar__logo{flex-shrink:0;object-fit:contain;height:80px;width:129px}.footer__logo-link{display:block;margin-bottom:8px}.footer__logo{object-fit:contain}</style>
    @yield('css')
</head>
<body class="{{ $bodyClass ?? 'home-v1' }}">
    @include('layout.growtech.partials._loader')
    @include('layout.growtech.partials._navbar', ['navbarFullScreen' => $navbarFullScreen ?? false])

    @if(!empty($navbarFullScreen))
    <div class="header-bg header-bg--full-screen"></div>
    @endif

    @yield('content')

    @if($showFooter ?? true)
    @include('layout.growtech.partials._footer')
    @endif

    <script src="{{ asset('growtech/assets/js/scripts.bundle.js') }}"></script>
    <script src="{{ asset('growtech/assets/plugins/gsap.min.js') }}"></script>
    <script src="{{ asset('growtech/assets/plugins/ScrollTrigger.min.js') }}"></script>
    <x-toast-messages />
    @stack('script')
    @yield('script')
</body>
</html>
