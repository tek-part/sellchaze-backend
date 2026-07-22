@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('New Quotation Received') }}</h2>
    <p style="margin:0 0 12px;">{{ __('A new quotation has been received for order :code.', ['code' => optional($quotation->order)->code]) }}</p>
    <div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
        <p style="margin:0 0 8px;"><strong>{{ __('Price') }}:</strong> {{ formatNumber($quotation->price, 2) }} {{ $quotation->currency ?? 'EGP' }}</p>
        <p style="margin:0;"><strong>{{ __('Delivery Date') }}:</strong> {{ optional($quotation->delivery_date)->format('Y-m-d') ?? 'N/A' }}</p>
    </div>
@endsection
