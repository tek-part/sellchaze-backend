<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>{{ $title ?? config('app.name', 'SELLCHASE') }}</title>
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-google')
    @endif
    @php $rizzCssBase = app()->getLocale() === 'ar' ? 'rizz/css/ar' : 'rizz/css'; @endphp
    <link rel="icon" type="image/png" href="{{ asset('icon.png') }}">
    <link href="{{ asset($rizzCssBase . '/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('rizz/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset($rizzCssBase . '/app.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
#kt_app_root{--landing-font:'Plus Jakarta Sans',system-ui,sans-serif;--landing-heading:#0f172a;--landing-muted:#64748b;--landing-border:#e2e8f0;--landing-bg-subtle:#f8fafc;--landing-shadow:0 1px 3px rgba(0,0,0,.06);--landing-shadow-hover:0 4px 12px rgba(0,0,0,.08);--landing-blue:#0073cf;--landing-teal:#00c2aa;--landing-blue-rgb:0,115,207;--landing-teal-rgb:0,194,170;--landing-section-py:3.5rem;--landing-section-py-lg:5rem;--landing-hero-py:5rem;--landing-hero-py-lg:8.5rem;font-family:var(--landing-font)}
.landing-section{padding-top:var(--landing-section-py);padding-bottom:var(--landing-section-py)}
@media(min-width:992px){.landing-section{padding-top:var(--landing-section-py-lg);padding-bottom:var(--landing-section-py-lg)}}
.landing-hero.landing-section{padding-top:var(--landing-hero-py);padding-bottom:var(--landing-hero-py);min-height:32rem;display:flex;align-items:center}
@media(min-width:992px){.landing-hero.landing-section{padding-top:var(--landing-hero-py-lg);padding-bottom:var(--landing-hero-py-lg);min-height:38rem}}
.landing-section .landing-section-title{margin-bottom:.375rem}
.landing-section .landing-section-lead{margin-bottom:2rem}
@media(min-width:992px){.landing-section .landing-section-lead{margin-bottom:2.5rem}}
.landing-logo{height:56px;object-fit:contain}
@media(min-width:576px){.landing-logo{height:60px}}
@media(min-width:992px){.landing-logo{height:75px}}
.app-landing-header{background:#fff;border-bottom:1px solid var(--landing-border);padding:.75rem 0}
.app-landing-header .landing-nav-lang{color:var(--landing-muted);font-size:.875rem;text-decoration:none}
.app-landing-header .btn-landing-nav{font-size:.875rem;font-weight:500;padding:.4rem .9rem;border-radius:.375rem}
.landing-hero{background:linear-gradient(165deg,rgba(var(--landing-blue-rgb),.07) 0%,rgba(var(--landing-teal-rgb),.05) 100%),#fff}
.landing-hero .landing-hero-title{color:var(--landing-heading);font-weight:800}
.landing-hero .landing-hero-subtitle{color:var(--landing-muted);max-width:32rem;line-height:1.6}
.landing-hero .btn-landing-hero-primary{border-radius:9999px;font-weight:600;padding:.6rem 1.5rem;background:linear-gradient(135deg,var(--landing-blue),var(--landing-teal));color:#fff}
.landing-hero .btn-landing-hero-secondary{border-radius:9999px;font-weight:600;padding:.6rem 1.5rem;border:1px solid var(--landing-border);background:#fff;color:var(--landing-heading)}
.landing-section-title{color:var(--landing-heading);font-weight:800}
.landing-section-lead{color:var(--landing-muted);max-width:28rem;line-height:1.6}
.landing-card-minimal{background:#fff;border:1px solid var(--landing-border);border-radius:.5rem;transition:box-shadow .25s,border-color .25s,transform .25s}
.landing-card-minimal:hover{box-shadow:var(--landing-shadow-hover);border-color:rgba(0,194,170,.25);transform:translateY(-2px)}
.landing-card-accent{border-left:3px solid var(--bs-primary);background:#fff;border-radius:0 .5rem .5rem 0;box-shadow:var(--landing-shadow)}
[dir=rtl] .landing-card-accent{border-left:1px solid var(--landing-border);border-right:3px solid var(--bs-primary);border-radius:.5rem 0 0 .5rem}
.landing-section-alt{background:var(--landing-bg-subtle)}
.landing-section .step-num{width:50px;height:50px;font-weight:700;border:2px solid var(--bs-primary);background:#fff;color:var(--bs-primary)}
.landing-cta-block{background:linear-gradient(135deg,var(--bs-primary),#0066b3);border-radius:.75rem}
@keyframes landingFadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes landingFadeIn{from{opacity:0}to{opacity:1}}
.landing-anim-fade-in-up{animation:landingFadeInUp .55s ease-out forwards}
.landing-anim-fade-in{animation:landingFadeIn .6s ease-out forwards}
.landing-anim-delay-1{animation-delay:.08s;opacity:0}
.landing-anim-delay-2{animation-delay:.16s;opacity:0}
.landing-anim-delay-3{animation-delay:.24s;opacity:0}
.landing-anim-delay-4{animation-delay:.32s;opacity:0}
    </style>
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-typography')
    @endif
    @yield('css')
</head>
<body>
    @include('components.page-loader')
    <div class="d-flex flex-column min-vh-100" id="kt_app_root">
        <header class="app-landing-header">
            <div class="container container-fluid">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <a href="{{ url('/') }}" class="d-flex align-items-center flex-shrink-0">
                        <img alt="{{ config('app.name', 'SELLCHASE') }}" src="{{ asset('logo.png') }}" class="landing-logo"/>
                    </a>
                    <div class="d-flex align-items-center flex-wrap gap-2 gap-lg-3 justify-content-end">
                        @if (!session()->has('locale') || session('locale') === 'en')
                            <a href="{{ route('locale.switch', 'ar') }}" class="landing-nav-lang">{{ __('Arabic') }}</a>
                        @else
                            <a href="{{ route('locale.switch', 'en') }}" class="landing-nav-lang">{{ __('English') }}</a>
                        @endif
                        <a href="{{ route('login') }}" class="btn btn-landing-nav btn-outline-primary">{{ __('Sign In') }}</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-landing-nav btn-primary">{{ __('Sign up') }}</a>
                        @else
                            <a href="{{ route('requestInvitation') }}" class="btn btn-landing-nav btn-primary">{{ __('Request an invitation') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-grow-1">
            {{ $slot }}
        </main>
    </div>
    <script src="{{ asset('rizz/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <x-toast-messages />
    @yield('script')
</body>
</html>
