<p style="margin:0 0 12px;">{{ __('Hello :name,', ['name' => $user_name]) }}</p>
<p style="margin:0 0 16px;">{{ __('We noticed a sign-in to your account from a new IP address or device.') }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;width:100%;font-size:14px;">
    <tr><td style="padding:6px 0;color:#64748b;">IP</td><td style="padding:6px 0;">{{ $ip }}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">{{ __('When') }}</td><td style="padding:6px 0;">{{ $when }}</td></tr>
</table>
<p style="margin:0 0 16px;font-size:13px;color:#64748b;">{{ __('If this was you, you can ignore this email.') }}</p>
<p style="margin:0;font-size:13px;color:#64748b;">{{ __('If you did not sign in, change your password from your profile and review active sessions.') }}</p>
