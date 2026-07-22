@extends('mail.layout', ['mailTitle' => config('app.name')])
@section('content')
    <p style="margin:0 0 12px;">
        {{ $type === 'inventory_out' ? __('This product is out of stock in a warehouse.') : __('Available quantity is at or below the low-stock threshold.') }}
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;width:100%;font-size:14px;">
        <tr><td style="padding:6px 0;color:#64748b;">{{ __('Product') }}</td><td style="padding:6px 0;">#{{ $productId }}</td></tr>
        <tr><td style="padding:6px 0;color:#64748b;">{{ __('Warehouse') }}</td><td style="padding:6px 0;">#{{ $warehouseId }}</td></tr>
        <tr><td style="padding:6px 0;color:#64748b;">{{ __('Available') }}</td><td style="padding:6px 0;">{{ $qtyAvailable }}</td></tr>
    </table>
@endsection
