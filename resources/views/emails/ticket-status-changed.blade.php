@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Ticket #:id - Status Updated', ['id' => $ticket->id]) }}</h2>
    <p style="margin:0 0 8px;">{{ __('Order') }}: {{ optional($ticket->order)->code ?? '—' }}</p>
    <p style="margin:0 0 8px;"><strong>{{ __('Type') }}:</strong> {{ ucfirst($ticket->type) }}</p>
    <p style="margin:0 0 12px;"><strong>{{ __('Status changed from') }}:</strong> {{ str_replace('_', ' ', ucfirst($oldStatus)) }} → {{ str_replace('_', ' ', ucfirst($ticket->status)) }}</p>
    <p style="margin:0;">{{ __('Please review the ticket for any required action.') }}</p>
@endsection
