@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <p style="margin:0 0 12px;">{{ __('Hello!') }}</p>
    <p style="margin:0 0 16px;">{{ __('You have been invited to join :app.', ['app' => config('app.name')]) }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
            {{ __('Accept invitation') }}
        </a>
    </p>
    @if(!empty($inviteCode))
        <p style="margin:0 0 8px;font-size:14px;">{{ __('Your invite code is: :code', ['code' => $inviteCode]) }}</p>
        <p style="margin:0;font-size:13px;color:#64748b;">{{ __('You can also register using this code on the invitation page.') }}</p>
    @endif
@endsection
