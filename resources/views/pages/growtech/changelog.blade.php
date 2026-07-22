@extends('layout.growtech.master')
@section('content')
<div class="header header--mini">
    <div class="pattern"></div>
    <div class="container" data-animation="slide-down">
        <h1>{{ __('Changelog') }}</h1>
        <p class="text-l-regular">{{ __('All updates and changes to the platform.') }}</p>
    </div>
</div>
<section class="version">
    <div class="container" data-animation="slide-right">
        <div class="header header--link" id="fonts">
            <div class="header-content">
                <div class="text-wrap">
                    <h2>V1.0</h2>
                    <p class="text-m-medium">{{ __('Initial release') }}</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
