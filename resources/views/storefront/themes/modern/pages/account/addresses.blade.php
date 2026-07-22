@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head" style="display:flex;justify-content:space-between;align-items:center"><div><h1>العناوين</h1><p>أدر عناوين الشحن الخاصة بك.</p></div><button class="mod-btn" type="button">+ إضافة عنوان</button></div>
        <div class="mod-values">
            @php $addr = [['المنزل', true, 'عبدالله الأحمد', 'حي النخيل، طريق الملك عبد العزيز', 'الرياض ١٢٣٤٥'], ['العمل', false, 'عبدالله الأحمد', 'طريق الملك فهد، برج المملكة', 'الرياض ١١٥٦٤']]; @endphp
            @foreach($addr as $a)
            <div class="mod-panel">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-2)"><strong>{{ $a[0] }}</strong>@if($a[1])<span class="mod-badge mod-badge--success">افتراضي</span>@endif</div>
                <div style="color:var(--muted);line-height:1.9">{{ $a[2] }}<br>{{ $a[3] }}<br>{{ $a[4] }}</div>
                <div style="display:flex;gap:var(--sp-2);margin-top:var(--sp-4)"><button class="mod-btn mod-btn--ghost" type="button" style="flex:1">تعديل</button><button class="mod-iconbtn" type="button" aria-label="حذف">🗑</button></div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
