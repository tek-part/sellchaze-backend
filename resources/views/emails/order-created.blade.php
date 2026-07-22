@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('New Order') }}</h2>
    <p style="margin:0 0 12px;">{{ __('Order :code has been created.', ['code' => $order->code]) }}</p>
    <div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;">
            <tr><td style="padding:6px 0;color:#64748b;">{{ __('Product') }}</td><td style="padding:6px 0;">{{ optional($order->product)->name ?? 'N/A' }}</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">{{ __('Quantity') }}</td><td style="padding:6px 0;">{{ $order->quantity }}</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">{{ __('Status') }}</td><td style="padding:6px 0;">{{ $order->status }}</td></tr>
        </table>
    </div>
@endsection
