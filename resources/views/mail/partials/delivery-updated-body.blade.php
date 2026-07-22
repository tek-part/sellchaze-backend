<p style="margin:0 0 12px;">{{ __('Shipping update for order :code', ['code' => $order_code]) }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;font-size:14px;">
    @if(!empty($tracking))
        <tr><td style="padding:6px 0;color:#64748b;">{{ __('Tracking') }}</td><td style="padding:6px 0;">{{ $tracking }}</td></tr>
    @endif
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('Status') }}</td><td style="padding:6px 0;">{{ $status }}</td></tr>
</table>
@if(!empty($tracking_url))
    <p style="margin:0;">
        <a href="{{ $tracking_url }}" style="color:#2563eb;">{{ __('Track shipment') }}</a>
    </p>
@endif
