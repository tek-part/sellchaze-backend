<!DOCTYPE html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $store->name }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,Arial,sans-serif;color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
<tr><td style="padding:28px 32px 8px;text-align:{{ $ar ? 'right' : 'left' }};">
@if ($logo)<img src="{{ $logo }}" alt="{{ $store->name }}" height="40" style="height:40px;max-width:200px;">@else<strong style="font-size:20px;">{{ $store->name }}</strong>@endif
</td></tr>
<tr><td style="padding:16px 32px 8px;text-align:{{ $ar ? 'right' : 'left' }};font-size:15px;line-height:1.7;">
<h1 style="font-size:20px;margin:0 0 12px;">{{ $ar ? 'إعادة تعيين كلمة المرور' : 'Reset your password' }}</h1>
<p style="margin:0 0 12px;">{{ $ar ? 'مرحبًا' : 'Hi' }} {{ $name }},</p>
<p style="margin:0 0 20px;">{{ $ar ? 'طلبت إعادة تعيين كلمة مرور حسابك في '.$store->name.'. اضغط الزر لاختيار كلمة مرور جديدة.' : 'We received a request to reset the password for your '.$store->name.' account. Use the button to choose a new password.' }}</p>
<p style="margin:0 0 24px;"><a href="{{ $url }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:8px;">{{ $ar ? 'اختيار كلمة مرور جديدة' : 'Choose a new password' }}</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#52606d;">{{ $ar ? 'ينتهي الرابط خلال '.$minutes.' دقيقة. إن لم تطلب ذلك فتجاهل هذه الرسالة، وستبقى كلمة مرورك كما هي.' : 'The link expires in '.$minutes.' minutes. If you did not ask for this, ignore this email and your password stays the same.' }}</p>
<p style="margin:0 0 24px;font-size:12px;color:#7b8794;word-break:break-all;">{{ $url }}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
