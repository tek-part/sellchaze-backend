@extends('storefront.themes.modern.layout')
@section('content')
@php $cur = $currency; $cols = count($items) + 1; @endphp
<div class="mod-page">
    <div class="mod-page__head"><h1>مقارنة المنتجات</h1><p>عرض المواصفات والأسعار لـ {{ count($items) }} منتجات مختارة جنباً إلى جنب.</p></div>
    <div style="overflow-x:auto">
        <table class="mod-ctable">
            <thead>
                <tr>
                    <td></td>
                    @foreach($items as $p)
                    <td style="min-width:190px">
                        <div class="mod-line__media" style="width:100%;height:130px;margin-bottom:8px">@if($p['image_url'])<img src="{{ $p['image_url'] }}" alt="{{ $p['name'] }}">@endif</div>
                        <div class="mod-line__name">{{ $p['name'] }}</div>
                        <div class="mod-price" style="margin:6px 0">{{ $p['price'] }} {{ $cur }}</div>
                        <a class="mod-btn" href="/products/{{ $p['slug'] }}" style="width:100%;justify-content:center">🛍 أضف للسلة</a>
                    </td>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr class="mod-ctable__group"><td colspan="{{ $cols }}">🔖 الإجمالي</td></tr>
                <tr><th>السعر</th>@foreach($items as $p)<td>{{ $p['price'] }} {{ $cur }}</td>@endforeach</tr>
                <tr><th>الحالة</th>@foreach($items as $p)<td>متوفر</td>@endforeach</tr>
                <tr><th>التقييم</th>@foreach($items as $p)<td>⭐ 4.6 (218)</td>@endforeach</tr>
                <tr class="mod-ctable__group"><td colspan="{{ $cols }}">🛡️ المواصفات الفنية</td></tr>
                <tr><th>الخامة</th>@foreach($items as $p)<td>ستانلس ستيل مقاوم</td>@endforeach</tr>
                <tr><th>الوزن</th>@foreach($items as $p)<td>52 جرام</td>@endforeach</tr>
                <tr><th>الأبعاد</th>@foreach($items as $p)<td>44 × 44 × 10.9 مم</td>@endforeach</tr>
                <tr class="mod-ctable__group"><td colspan="{{ $cols }}">⚙️ الخيارات المتاحة</td></tr>
                <tr><th>الألوان</th>@foreach($items as $p)<td>أسود / فضي / ذهبي</td>@endforeach</tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
