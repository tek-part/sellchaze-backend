@php
    $bodyClass = 'home-v1';
    $pageCss = 'home-v1.css';
    $showFooter = false;
@endphp
@extends('layout.growtech.master')
@section('content')
    {{ $slot }}
@endsection
