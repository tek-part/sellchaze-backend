@extends('layout.rizz.master')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="page-title-box d-flex align-items-center justify-content-between py-3">
            @include('layout.rizz.partials._page-title')
        </div>
    </div>
</div>
{{ $slot }}
@endsection

@section('script')
{!! $script ?? '' !!}
@endsection
