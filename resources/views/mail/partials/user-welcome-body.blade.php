<p style="margin:0 0 12px;">{{ __('Hello!') }}</p>
<p style="margin:0 0 16px;">{{ __('Welcome to :app.', ['app' => $app_name]) }}</p>
@if(!empty($profile_url))
    <p style="margin:0;">
        <a href="{{ $profile_url }}" style="display:inline-block;padding:12px 20px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
            {{ __('View profile') }}
        </a>
    </p>
@endif
