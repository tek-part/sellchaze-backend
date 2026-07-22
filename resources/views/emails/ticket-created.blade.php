@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - Order :code', ['id' => $ticket->id, 'code' => optional($ticket->order)->code ?? '—']) }}</h2>
    <p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ ucfirst($ticket->type) }}</p>
    <p style="margin:0 0 8px;"><strong>{{ __('Notes') }}:</strong></p>
    <div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{{ $ticket->notes }}</div>
    <p style="margin:16px 0 0;">{{ __('Please review and take the required action.') }}</p>
@endsection
