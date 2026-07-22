<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mailTitle ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;">
        <tr>
            <td align="center" style="padding:28px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:22px 28px;background:#0f172a;">
                            <p style="margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#f8fafc;">
                                {{ $mailTitle ?? config('app.name') }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:15px;line-height:1.65;color:#334155;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:12px;line-height:1.5;color:#64748b;">
                            {{ __('This message was sent by :app.', ['app' => config('app.name')]) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
