<p style="margin:0 0 12px;">
    {{ __('You have been assigned to fulfill an order. Please review the details below.') }}
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;width:100%;font-size:14px;">
    <tr>
        <td style="padding:6px 0;color:#64748b;">{{ __('Order') }}</td>
        <td style="padding:6px 0;">{{ $orderCode }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0;color:#64748b;">{{ __('Product') }}</td>
        <td style="padding:6px 0;">{{ $productName }}</td>
    </tr>
    <tr>
        <td style="padding:6px 0;color:#64748b;">{{ __('App') }}</td>
        <td style="padding:6px 0;">{{ $appName }}</td>
    </tr>
</table>
