@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>الملف الشخصي</h1><p>حدّث معلوماتك الشخصية.</p></div>
        <div class="mod-panel" style="max-width:640px">
            <div style="display:flex;gap:var(--sp-4);align-items:center;margin-bottom:var(--sp-6)">
                <div class="mod-avatar" style="overflow:hidden"><img src="https://i.pravatar.cc/120?img=13" alt="الصورة الشخصية" style="width:100%;height:100%;object-fit:cover"></div>
                <div><strong>عبدالله الأحمد</strong><div style="color:var(--muted)">عضو منذ ٢٠٢٣</div></div>
                <button class="mod-btn mod-btn--ghost" type="button" style="margin-inline-start:auto">تغيير الصورة</button>
            </div>
            <div class="mod-field"><label>الاسم الكامل</label><input class="mod-input" value="عبدالله الأحمد"></div>
            <div class="mod-field"><label>البريد الإلكتروني</label><input class="mod-input" type="email" value="abdullah@example.com"></div>
            <div class="mod-field"><label>رقم الهاتف</label><input class="mod-input" value="+966 55 123 4567"></div>
            <button class="mod-btn" type="button">حفظ التغييرات</button>
        </div>
    </div>
</div>
@endsection
