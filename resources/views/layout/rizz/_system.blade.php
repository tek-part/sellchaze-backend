<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>{{ $title ?? config('app.name', 'SELLCHAZE') }}</title>
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-google')
    @endif
    @php $rizzCssBase = app()->getLocale() === 'ar' ? 'rizz/css/ar' : 'rizz/css'; @endphp
    <link rel="icon" type="image/png" href="{{ asset('icon.png') }}">
    <link href="{{ asset($rizzCssBase . '/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('rizz/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset($rizzCssBase . '/app.min.css') }}" rel="stylesheet" type="text/css" />
    @if (file_exists(public_path('rizz/css/sellchase-theme.css')))
    <link href="{{ asset('rizz/css/sellchase-theme.css') }}" rel="stylesheet" type="text/css" />
    @endif
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-typography')
    @endif
    @stack('rizz-css')
</head>
<body>
    <div class="container-xxl">
        <div class="row vh-100 d-flex justify-content-center align-items-center">
            <div class="col-12 align-self-center">
                <div class="row justify-content-center">
                    <div class="col-lg-5 col-md-6">
                        <div class="card">
                            <div class="card-body p-4 text-center">
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
