<p style="margin:0 0 16px;">{{ __('Hello!') }}</p>
<p style="margin:0 0 16px;">{{ __('You are receiving this email because we received a password reset request for your account.') }}</p>
<p style="margin:0 0 22px;">
    <a href="{{ $reset_url }}" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        {{ __('Reset Password') }}
    </a>
</p>
<p style="margin:0 0 12px;font-size:13px;color:#64748b;">{{ __('This password reset link will expire in :count minutes.', ['count' => $expire_minutes]) }}</p>
<p style="margin:0;font-size:13px;color:#64748b;">{{ __('If you did not request a password reset, no further action is required.') }}</p>
