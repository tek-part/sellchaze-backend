@php
    $navbarFullScreen = true;
    $showFooter = false;
    $formTitle = $formTitle ?? __('Sign In');
    $formSubtitle = $formSubtitle ?? __('Fill your email and password to sign in');
@endphp
@extends('layout.growtech.master')
@section('content')
<section class="landing">
    <div class="header-bg header-bg--full-screen"></div>
    <div class="container" data-animation="fade-in">
        <div class="form__header">
            <h3>{{ $formTitle }}</h3>
            <p class="text-l-regular">{{ $formSubtitle }}</p>
        </div>
        <div class="form__body">
            {{ $slot }}
        </div>
    </div>
</section>
@endsection
