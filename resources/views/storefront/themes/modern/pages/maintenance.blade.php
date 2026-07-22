@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-centered"><div class="mod-centered__inner">
    <div class="mod-brand" style="font-size:var(--fs-2xl)">{{ $context['store']['name'] }}</div>
    <div style="font-size:56px">🛠️</div>
    <h1 style="font-size:var(--fs-2xl)">نقوم بأعمال صيانة</h1>
    <p style="color:var(--muted)">نعمل على تحسين تجربتك. سنعود قريباً جداً — شكراً لصبرك.</p>
    <div style="color:var(--muted)">الوقت المتوقع للعودة: خلال ساعتين ⏱</div>
    <div style="display:flex;gap:16px;color:var(--muted)">📧 support@sellchase.com<span>·</span>📞 800 123 4567</div>
</div></div>
@endsection
