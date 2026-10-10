<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<body style="font-family:Arial,sans-serif;color:#172033;background:#f5f7fb;padding:24px">
<main style="max-width:640px;margin:auto;background:white;padding:24px;border-radius:12px">
<h1>{{ $ar ? 'تم تأكيد الدفع' : 'Payment confirmed' }}</h1>
<p>{{ $store->name }} — {{ $order->order_number }}</p>
@foreach ($lines as $line)
@if ($line['delivery'] && $line['delivery']['status'] === 'ready')
<h2>{{ $line['name'] }}</h2>
@foreach ($line['delivery']['values'] as $value)
@if ($line['delivery']['type'] === 'link')
<p><a href="{{ $value }}" rel="noreferrer">{{ $ar ? 'استلام المنتج' : 'Access your product' }}</a></p>
@else
<p style="white-space:pre-wrap;direction:ltr;text-align:left;font-family:monospace">{{ $value }}</p>
@endif
@endforeach
@endif
@endforeach
@if ($receiptUrl)<p><a href="{{ $receiptUrl }}">{{ $ar ? 'عرض الإيصال الخاص' : 'View your private receipt' }}</a></p>@endif
<p>{{ $ar ? 'احتفظ بهذا البريد والرابط لنفسك، فهما يتيحان الوصول إلى منتجاتك.' : 'Keep this email and link private: they provide access to your products.' }}</p>
</main></body></html>
