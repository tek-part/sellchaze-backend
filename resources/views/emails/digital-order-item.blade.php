<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<body style="font-family:Arial,sans-serif;color:#172033;background:#f5f7fb;padding:24px">
<main style="max-width:640px;margin:auto;background:white;padding:24px;border-radius:12px">
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $body }}</p>
@if ($receiptUrl)<p><a href="{{ $receiptUrl }}">{{ $ar ? 'عرض الإيصال الخاص' : 'View your private receipt' }}</a></p>@endif
<p>{{ $ar ? 'احتفظ بهذه الرسالة والرابط لنفسك، فهما يتيحان الوصول إلى منتجك.' : 'Keep this message and link private: they provide access to your product.' }}</p>
</main></body></html>
