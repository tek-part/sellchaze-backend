<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>
@php($align = $ar ? 'right' : 'left')
@php($opposite = $ar ? 'left' : 'right')
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Tahoma,Arial,sans-serif;color:#1f2933">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
    <tr><td style="padding:24px 28px;border-bottom:1px solid #eef0f3;text-align:{{ $align }}">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $store->name }}" style="max-height:48px;max-width:200px;display:inline-block">
        @else
            <span style="font-size:20px;font-weight:700;color:{{ $accent }}">{{ $store->name }}</span>
        @endif
        <span style="float:{{ $opposite }};font-size:13px;color:#6b7280;line-height:48px">{{ $ar ? 'طلب' : 'Order' }} {{ $order->order_number }}</span>
    </td></tr>
    <tr><td style="padding:28px;text-align:{{ $align }}">
        <div style="font-size:15px;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere">{{ $body }}</div>
        @if ($orderUrl)
            <p style="margin:24px 0 0"><a href="{{ $orderUrl }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:8px">{{ $staff ? ($ar ? 'فتح الطلب في لوحة التحكم' : 'Open order in dashboard') : ($ar ? 'عرض طلبك' : 'View your order') }}</a></p>
        @endif
        @if ($tracking)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;background:#f8fafc;border-radius:8px">
                <tr><td style="padding:14px 16px;font-size:14px;text-align:{{ $align }}">
                    <strong>{{ $ar ? 'تتبع الشحنة' : 'Tracking' }}</strong><br>
                    {{ $tracking['carrier'] }} · {{ $tracking['number'] }}
                    @if ($tracking['url'])<br><a href="{{ $tracking['url'] }}" style="color:{{ $accent }}">{{ $ar ? 'تتبع الشحنة' : 'Track your shipment' }}</a>@endif
                </td></tr>
            </table>
        @endif
    </td></tr>
    @if ($items->isNotEmpty())
    <tr><td style="padding:0 28px 8px;text-align:{{ $align }}">
        <h2 style="font-size:16px;margin:0 0 12px">{{ $ar ? 'ملخص الطلب' : 'Order summary' }}</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
            @foreach ($items as $item)
                <tr>
                    <td style="padding:8px 0;border-bottom:1px solid #eef0f3;text-align:{{ $align }}">{{ $item->name }}@if ($item->variant_name)<br><span style="color:#6b7280;font-size:12px">{{ $item->variant_name }}</span>@endif <span style="color:#6b7280">× {{ $item->quantity }}</span></td>
                    <td style="padding:8px 0;border-bottom:1px solid #eef0f3;text-align:{{ $opposite }};white-space:nowrap">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
            <tr><td style="padding:10px 0 2px;color:#6b7280;text-align:{{ $align }}">{{ $ar ? 'المجموع الفرعي' : 'Subtotal' }}</td><td style="padding:10px 0 2px;text-align:{{ $opposite }}">{{ $money($order->subtotal) }}</td></tr>
            @if ((float) $order->discount_total > 0)
                <tr><td style="padding:2px 0;color:#6b7280;text-align:{{ $align }}">{{ $ar ? 'الخصم' : 'Discount' }}</td><td style="padding:2px 0;text-align:{{ $opposite }}">−{{ $money($order->discount_total) }}</td></tr>
            @endif
            <tr><td style="padding:2px 0;color:#6b7280;text-align:{{ $align }}">{{ $ar ? 'الشحن' : 'Shipping' }}</td><td style="padding:2px 0;text-align:{{ $opposite }}">{{ $money($order->shipping_total) }}</td></tr>
            @if ((float) $order->tax_total > 0)
                <tr><td style="padding:2px 0;color:#6b7280;text-align:{{ $align }}">{{ $ar ? 'الضريبة' : 'Tax' }}</td><td style="padding:2px 0;text-align:{{ $opposite }}">{{ $money($order->tax_total) }}</td></tr>
            @endif
            <tr><td style="padding:10px 0;font-weight:700;font-size:16px;text-align:{{ $align }}">{{ $ar ? 'الإجمالي' : 'Total' }}</td><td style="padding:10px 0;font-weight:700;font-size:16px;text-align:{{ $opposite }}">{{ $money($order->grand_total) }}</td></tr>
            @if (bccomp((string) ($order->gift_card_total ?? '0'), '0', 2) > 0)
            <tr><td style="padding:4px 0;text-align:{{ $align }}">{{ $ar ? 'مدفوع ببطاقات الهدايا' : 'Paid with gift cards' }}</td><td style="padding:4px 0;text-align:{{ $opposite }}">−{{ $money($order->gift_card_total) }}</td></tr>
            <tr><td style="padding:4px 0;font-weight:700;text-align:{{ $align }}">{{ $ar ? 'المبلغ المستحق' : 'Amount due' }}</td><td style="padding:4px 0;font-weight:700;text-align:{{ $opposite }}">{{ $money($order->payment_status === 'paid' ? '0.00' : $order->amountDue()) }}</td></tr>
            @endif
        </table>
    </td></tr>
    @endif
    @if ($address || $payment)
    <tr><td style="padding:8px 28px 24px;text-align:{{ $align }};font-size:14px;line-height:1.6">
        @if ($address)<p style="margin:0 0 8px"><strong>{{ $ar ? 'عنوان الشحن' : 'Shipping address' }}</strong><br>{{ $address }}</p>@endif
        @if ($payment)<p style="margin:0"><strong>{{ $ar ? 'طريقة الدفع' : 'Payment' }}</strong><br>{{ $payment }}</p>@endif
    </td></tr>
    @endif
    <tr><td style="padding:18px 28px;background:#fafbfc;border-top:1px solid #eef0f3;font-size:12px;color:#6b7280;text-align:{{ $align }}">
        {{ $store->name }}@if ($store->phone) · {{ $store->phone }}@endif
        @unless ($staff)<br>{{ $ar ? 'تواصل معنا بالرد على هذه الرسالة إذا كان لديك أي سؤال.' : 'Questions? Reply to this email and we\'ll help.' }}@endunless
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
