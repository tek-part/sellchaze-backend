<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">{{ __('Deal confirmed') }}</h2>
<p style="margin:0 0 16px;">{{ __('Your quotation has been accepted by the customer.') }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;font-size:14px;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('Order / Ref') }}</td><td style="padding:6px 0;">{{ $refnum }}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('Supplier') }}</td><td style="padding:6px 0;">{{ $supplier }}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('Customer') }}</td><td style="padding:6px 0;">{{ $customer }}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('Price') }}</td><td style="padding:6px 0;">{{ $price }}</td></tr>
</table>
<p style="margin:0;font-size:14px;color:#64748b;">{{ __('Thank you for using :app.', ['app' => $app_name]) }}</p>
