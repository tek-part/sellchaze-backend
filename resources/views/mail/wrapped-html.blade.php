@extends('mail.layout', ['mailTitle' => $mailTitle ?? config('app.name')])
@section('content')
{!! $html !!}
@endsection
