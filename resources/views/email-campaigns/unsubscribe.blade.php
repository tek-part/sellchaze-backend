<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'إلغاء الاشتراك' : 'Unsubscribe' }}{{ $store ? ' · '.$store->name : '' }}</title>
</head>
@php($name = $store?->name)
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Tahoma,Arial,sans-serif;color:#1f2933;padding:16px;box-sizing:border-box">
<main style="width:100%;max-width:440px;background:#ffffff;border-radius:12px;padding:32px 28px;box-sizing:border-box;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08)">
    @if ($store?->logoUrl())
        <img src="{{ $store->logoUrl() }}" alt="{{ $name }}" style="max-height:48px;max-width:180px;margin-bottom:16px">
    @elseif ($name)
        <p style="margin:0 0 16px;font-size:18px;font-weight:700">{{ $name }}</p>
    @endif
    @switch($state)
        @case('confirm')
            <h1 style="margin:0 0 12px;font-size:22px">{{ $ar ? 'إلغاء الاشتراك في الرسائل البريدية' : 'Unsubscribe from marketing emails' }}</h1>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#4b5563">{{ $ar ? 'لن تصلك رسائل تسويقية بالبريد الإلكتروني من '.$name.' بعد الآن. ستظل تصلك رسائل طلباتك.' : 'You will no longer receive marketing emails from '.$name.'. You will still get emails about your orders.' }}</p>
            <form method="post" action="{{ $action }}">
                <button type="submit" style="font:inherit;font-size:16px;font-weight:600;background:#111827;color:#ffffff;border:0;border-radius:8px;padding:12px 28px;cursor:pointer">{{ $ar ? 'إلغاء الاشتراك' : 'Unsubscribe' }}</button>
            </form>
            @break
        @case('done')
            <h1 style="margin:0 0 12px;font-size:22px">{{ $ar ? 'تم إلغاء اشتراكك' : 'You have been unsubscribed' }}</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#4b5563">{{ $ar ? 'لن نرسل لك رسائل تسويقية بالبريد الإلكتروني من '.$name.' بعد الآن.' : 'You will no longer receive marketing emails from '.$name.'.' }}</p>
            @break
        @case('test')
            <h1 style="margin:0 0 12px;font-size:22px">{{ $ar ? 'هذه رسالة تجريبية' : 'This was a test email' }}</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#4b5563">{{ $ar ? 'رابط إلغاء الاشتراك يعمل في الرسائل الفعلية للمشتركين. لم يتغير شيء.' : 'The unsubscribe link works in the real emails sent to subscribers. Nothing was changed.' }}</p>
            @break
        @default
            <h1 style="margin:0 0 12px;font-size:22px">{{ $ar ? 'الرابط غير صالح' : 'This link is not valid' }}</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#4b5563">{{ $ar ? 'ربما انتهت صلاحية الرابط أو نُسخ بشكل غير كامل.' : 'The link may be incomplete or no longer available.' }}</p>
    @endswitch
    @if ($homeUrl && $state !== 'confirm')
        <p style="margin:8px 0 0"><a href="{{ $homeUrl }}" style="color:#2563eb">{{ $ar ? 'العودة إلى المتجر' : 'Back to the store' }}</a></p>
    @endif
</main>
</body>
</html>
