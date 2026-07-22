@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-account">
    @include('storefront.themes.modern.pages.account._nav', ['active' => $active])
    <div>
        <div class="mod-page__head"><h1>الإعدادات</h1></div>
        <div class="mod-panel" style="max-width:640px">
            <h3>الإشعارات</h3>
            <div class="mod-setrow"><div><strong>عروض وتخفيضات</strong><div style="color:var(--muted);font-size:14px">استلم أحدث العروض عبر البريد.</div></div><span class="mod-sw is-on"></span></div>
            <div class="mod-setrow"><div><strong>تحديثات الطلبات</strong><div style="color:var(--muted);font-size:14px">إشعارات حالة الشحن والتسليم.</div></div><span class="mod-sw is-on"></span></div>
            <div class="mod-setrow" style="border-bottom:0"><div><strong>النشرة البريدية</strong><div style="color:var(--muted);font-size:14px">مقالات ونصائح أسبوعية.</div></div><span class="mod-sw"></span></div>
            <h3 style="margin-top:var(--sp-6)">التفضيلات</h3>
            <div class="mod-field"><label>اللغة</label><select class="mod-input"><option>العربية</option><option>English</option></select></div>
            <div class="mod-field"><label>العملة</label><select class="mod-input"><option>ر.س SAR</option><option>ج.م EGP</option></select></div>
            <hr style="border:none;border-top:1px solid var(--border);margin:var(--sp-6) 0">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4)">
                <div><strong style="color:var(--danger)">حذف الحساب</strong><div style="color:var(--muted);font-size:14px">إجراء نهائي لا يمكن التراجع عنه.</div></div>
                <button class="mod-btn mod-btn--ghost" type="button" style="border-color:var(--danger);color:var(--danger)">حذف</button>
            </div>
        </div>
    </div>
</div>
@endsection
