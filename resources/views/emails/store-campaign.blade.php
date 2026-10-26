<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $subject }}</title></head>
@php($align = $ar ? 'right' : 'left')
@php($opposite = $ar ? 'left' : 'right')
@php($alignOf = fn ($value) => match ($value ?? 'start') { 'center' => 'center', 'end' => $opposite, default => $align })
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Tahoma,Arial,sans-serif;color:#1f2933">
@if ($preheader !== '')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
    <tr><td style="padding:24px 28px;border-bottom:1px solid #eef0f3;text-align:center">
        <a href="{{ $homeUrl }}" style="text-decoration:none">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $store->name }}" style="max-height:48px;max-width:200px;display:inline-block;border:0">
        @else
            <span style="font-size:20px;font-weight:700;color:{{ $accent }}">{{ $store->name }}</span>
        @endif
        </a>
    </td></tr>
    <tr><td style="padding:28px;text-align:{{ $align }}">
    @foreach ($blocks as $block)
        @switch($block['type'])
            @case('heading')
                <h{{ $block['size'] === 'medium' ? 2 : 1 }} style="margin:0 0 16px;font-size:{{ $block['size'] === 'medium' ? '20px' : '26px' }};line-height:1.3;color:#111827;text-align:{{ $alignOf($block['align'] ?? null) }}">{{ $block['text'] }}</h{{ $block['size'] === 'medium' ? 2 : 1 }}>
                @break
            @case('text')
                <div style="margin:0 0 16px;font-size:15px;line-height:1.7;overflow-wrap:anywhere;text-align:{{ $alignOf($block['align'] ?? null) }}">{!! $block['html'] !!}</div>
                @break
            @case('image')
                <div style="margin:0 0 16px;text-align:center">
                    @if (! empty($block['href']))<a href="{{ $block['href'] }}" style="text-decoration:none">@endif
                    <img src="{{ $block['src'] }}" alt="{{ $block['alt'] ?? '' }}" width="544" style="width:100%;max-width:544px;height:auto;display:block;border:0;border-radius:8px">
                    @if (! empty($block['href']))</a>@endif
                </div>
                @break
            @case('button')
                <p style="margin:8px 0 20px;text-align:{{ $alignOf($block['align'] ?? null) }}"><a href="{{ $block['href'] }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:8px">{{ $block['label'] }}</a></p>
                @break
            @case('products')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px">
                    @foreach (array_chunk($block['items'], 2) as $row)
                        <tr>
                        @foreach ($row as $item)
                            <td width="50%" valign="top" style="padding:6px;text-align:{{ $align }}">
                                <a href="{{ $item['href'] }}" style="text-decoration:none;color:#111827">
                                    @if ($item['image'])<img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" width="260" style="width:100%;max-width:260px;height:auto;display:block;border:0;border-radius:8px;margin-bottom:8px">@endif
                                    <span style="display:block;font-size:14px;font-weight:600;line-height:1.4">{{ $item['name'] }}</span>
                                    <span style="display:block;font-size:14px;color:{{ $accent }};margin-top:4px">{{ $item['price'] }}@if ($item['compare']) <s style="color:#9ca3af;font-size:12px">{{ $item['compare'] }}</s>@endif</span>
                                </a>
                            </td>
                        @endforeach
                        @if (count($row) === 1)<td width="50%"></td>@endif
                        </tr>
                    @endforeach
                </table>
                @break
            @case('divider')
                <hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">
                @break
            @case('coupon')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border:2px dashed {{ $accent }};border-radius:10px">
                    <tr><td style="padding:18px;text-align:center">
                        @php($value = $block['value'])
                        <div style="font-size:16px;font-weight:700;color:#111827">
                            @if ($value['type'] === 'free_shipping'){{ $ar ? 'شحن مجاني' : 'Free shipping' }}
                            @elseif ($value['amount']){{ $ar ? 'خصم '.$value['amount'] : $value['amount'].' off' }}
                            @else{{ $ar ? 'عرض خاص' : 'Special offer' }}@endif
                        </div>
                        @if (! empty($block['text']))<div style="font-size:14px;color:#4b5563;margin-top:6px">{{ $block['text'] }}</div>@endif
                        <div style="font-size:12px;color:#6b7280;margin-top:12px">{{ $ar ? 'استخدم الكود عند الدفع' : 'Use this code at checkout' }}</div>
                        <div dir="ltr" style="display:inline-block;margin-top:6px;padding:8px 18px;background:#f3f4f6;border-radius:6px;font-family:Menlo,Consolas,monospace;font-size:20px;font-weight:700;letter-spacing:2px;color:#111827">{{ $block['code'] }}</div>
                        @if (! empty($block['expires_at']))<div style="font-size:12px;color:#6b7280;margin-top:8px">{{ $ar ? 'صالح حتى' : 'Valid until' }} {{ $block['expires_at'] }}</div>@endif
                    </td></tr>
                </table>
                @break
        @endswitch
    @endforeach
    </td></tr>
    <tr><td style="padding:18px 28px;background:#fafbfc;border-top:1px solid #eef0f3;font-size:12px;line-height:1.6;color:#6b7280;text-align:center">
        {{ $store->name }}@if ($store->phone) · <span dir="ltr">{{ $store->phone }}</span>@endif<br>
        {{ $ar ? 'تصلك هذه الرسالة لأنك اشتركت في النشرة البريدية للمتجر.' : 'You are receiving this email because you subscribed to this store\'s newsletter.' }}<br>
        <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;text-decoration:underline">{{ $ar ? 'إلغاء الاشتراك' : 'Unsubscribe' }}</a>
    </td></tr>
</table>
</td></tr>
</table>
@if ($openPixel)<img src="{{ $openPixel }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">@endif
</body>
</html>
