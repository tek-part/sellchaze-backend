@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <p style="margin:0 0 12px;">{{ $payload['greeting'] ?? '' }}</p>
    <p style="margin:0 0 20px;">{{ $payload['body'] ?? '' }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $payload['actionURL'] ?? '#' }}" style="display:inline-block;padding:12px 20px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
            {{ $payload['actionText'] ?? __('View') }}
        </a>
    </p>
    <p style="margin:0;">{{ $payload['thanks'] ?? '' }}</p>
@endsection
