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
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-typography')
    @endif
    @stack('rizz-css')
</head>
<body>
    @include('components.page-loader')
    <div class="container-xxl">
        <div class="row vh-100 d-flex justify-content-center align-items-center">
            <div class="col-12 align-self-center">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6">
                        <div class="card">
                            <div class="card-body p-0 bg-black auth-header-box rounded-top">
                                <div class="text-center p-3">
                                    <a href="{{ url('/') }}" class="logo logo-admin">
                                        <img src="{{ asset('logo.png') }}" height="50" alt="{{ config('app.name') }}" class="auth-logo" style="object-fit:contain">
                                    </a>
                                    <h4 class="mt-3 mb-1 fw-semibold text-white fs-18">{{ config('app.name', 'SELLCHASE') }}</h4>
                                    <p class="text-white-50 fw-medium mb-0">{{ __('B2B orders and quotations') }}</p>
                                </div>
                            </div>
                            <div class="card-body pt-4">
                                {{ $slot }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="{{ asset('rizz/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <x-toast-messages />
    @stack('rizz-js')
</body>
</html>
