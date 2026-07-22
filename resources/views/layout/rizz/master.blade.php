<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-startbar="light" data-bs-theme="light">
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
    <link href="{{ asset('rizz/libs/datatables/datatables.bundle.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('rizz/css/sellchase-theme.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset($rizzCssBase . '/crm-pages.css') }}" rel="stylesheet" type="text/css" />
    @if (app()->getLocale() === 'ar')
        @include('layout.rizz.partials._fonts-ar-typography')
    @endif
    <link href="{{ asset('rizz/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('assets/select2/css/select2.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet" type="text/css" />
    @stack('rizz-css')
    @yield('css')
</head>
<body>
    @include('components.page-loader')
    @include('layout.rizz.partials._topbar')
    @include('layout.rizz.partials._startbar')
    <div class="startbar-overlay d-print-none"></div>

    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-xxl">
                @yield('content')
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="{{ asset('rizz/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('rizz/libs/simplebar/simplebar.min.js') }}"></script>
    <script src="{{ asset('rizz/libs/datatables/datatables.bundle.js') }}"></script>
    <script src="{{ asset('rizz/libs/sweetalert2/sweetalert2.min.js') }}"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="{{ asset('rizz/js/rizz-notify.js') }}"></script>
    <script src="{{ asset('rizz/js/rizz-bulk-delete.js') }}"></script>
    <script src="{{ asset('rizz/js/crm-listing.js') }}"></script>
    <script src="{{ asset('rizz/js/app.js') }}"></script>
    <script src="{{ asset('assets/select2/js/select2.full.min.js') }}"></script>
    <script src="{{ asset('assets/js/select2-init.js') }}"></script>
    <x-toast-messages />
    @stack('rizz-js')
    @yield('script')
</body>
</html>
