@php $gt = asset('growtech/assets'); @endphp
@extends('layout.growtech.master')
@section('content')
<section class="landing">
    <div class="container" data-animation="fade-in">
        <img height="104" width="104" src="{{ $gt }}/media/images/icons/green-circle-check.svg" alt=""/>
        <div class="text-wrap">
            <h3>{{ __('Email Confirmation') }}</h3>
            <p class="text-m-regular">{{ __('Good job! Your email address is now confirmed and you can start using :name.', ['name' => $siteTitle ?? config('app.name')]) }}</p>
        </div>
        <a class="btn-wrap" href="{{ route('login') }}" style="width: 100%">
            <button class="btn btn--medium btn--primary" type="button" style="width: 100%">{{ __('Sign In') }}</button>
        </a>
    </div>
</section>
@endsection
