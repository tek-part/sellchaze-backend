<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<body style="font-family:Arial,sans-serif;color:#172033;background:#f5f7fb;padding:24px">
<main style="max-width:640px;margin:auto;background:white;padding:24px;border-radius:12px">
<h1>{{ $ar ? 'تم استلام طلبك' : 'Your order is saved' }}</h1>
<p>{{ $store->name }} — {{ $order->order_number }}</p>
<p>{{ $ar ? 'الإجمالي' : 'Total' }}: {{ $order->currency }} {{ $order->grand_total }}</p>
<p>{{ $ar ? 'بانتظار تأكيد الدفع. المنتجات الرقمية متاحة بعد وصول المبلغ وتأكيده.' : 'Awaiting payment confirmation. Digital products become available after payment is confirmed.' }}</p>
@if ($bank)
<h2>{{ $ar ? 'تعليمات التحويل البنكي' : 'Bank transfer instructions' }}{{ $bank['test_mode'] ? ($ar ? ' (تجريبي)' : ' (Test)') : '' }}</h2>
@foreach ($bank['fields'] as $key => $value)
<p>{{ ['account_name' => $ar ? 'اسم المستفيد' : 'Account holder', 'bank_name' => $ar ? 'البنك' : 'Bank', 'iban' => 'IBAN', 'swift_code' => 'SWIFT'][$key] ?? $key }}: <bdi>{{ $value }}</bdi></p>
@endforeach
<p style="white-space:pre-wrap">{{ $bank['notes'] }}</p>
<p>{{ $ar ? 'اذكر رقم الطلب كمرجع للتحويل.' : 'Use your order number as the transfer reference.' }}</p>
@endif
@if ($receiptUrl)<p><a href="{{ $receiptUrl }}">{{ $ar ? 'عرض الإيصال ومتابعة الدفع' : 'View your receipt and payment status' }}</a></p>@endif
<p>{{ $ar ? 'احتفظ بالرابط لنفسك، فهو يتيح الوصول إلى منتجاتك بعد الدفع.' : 'Keep this link private: it provides access to your products after payment.' }}</p>
</main></body></html>
