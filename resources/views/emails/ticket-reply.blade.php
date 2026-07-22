@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - New Reply', ['id' => $ticket->id]) }}</h2>
    <p style="margin:0 0 8px;">{{ __('Order') }}: {{ optional($ticket->order)->code ?? '—' }}</p>
    <p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ ucfirst($ticket->type) }}</p>
    <p style="margin:0 0 8px;"><strong>{{ __('New message') }}:</strong></p>
    <div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{{ $message->body }}</div>
    <p style="margin:12px 0 0;font-size:13px;color:#64748b;">{{ __('From') }}: {{ optional($message->user)->name ?? __('System') }} — {{ $message->created_at->format('d M Y H:i') }}</p>
    <p style="margin:16px 0 0;">{{ __('Please review and reply if needed.') }}</p>
@endsection
