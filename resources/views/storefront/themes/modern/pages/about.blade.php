@extends('storefront.themes.modern.layout')
@section('content')
<div class="mod-page">
    <div class="mod-band" style="margin-bottom:var(--sp-8);background-image:linear-gradient(90deg,rgba(14,124,102,.92),rgba(14,124,102,.55)),url('https://picsum.photos/seed/sc-about-hero/1400/500');background-size:cover;background-position:center">
        <span style="opacity:.85">قصتنا</span>
        <h2>نصنع تجربة تسوّق استثنائية</h2>
        <p style="opacity:.9;max-width:60ch;margin:var(--sp-3) auto 0">منذ ٢٠٢٣ ونحن نجمع بين الجودة والفخامة لنقدّم لعملائنا أفضل المنتجات بأفضل الأسعار.</p>
    </div>
    <div class="mod-two" style="margin-bottom:var(--sp-8);align-items:center">
        <div><h2 style="font-size:var(--fs-2xl)">من نحن</h2><p style="color:var(--muted);line-height:1.9">نؤمن أن التسوّق يجب أن يكون سهلاً وممتعاً وموثوقاً. نعمل مع نخبة من الموردين لضمان أصالة كل منتج، ونوفّر خدمة عملاء متميزة على مدار الساعة، وتجربة شراء سلسة من التصفح حتى التوصيل. نبني علاقة طويلة الأمد مع عملائنا قائمة على الشفافية والثقة.</p></div>
        <img src="https://picsum.photos/seed/sc-about-story/800/600" alt="فريق العمل" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:var(--radius-lg)">
    </div>
    <div class="mod-gallery" style="margin-bottom:var(--sp-8)">
        @foreach(['warehouse','team2','packaging','delivery'] as $g)<img src="https://picsum.photos/seed/sc-about-{{ $g }}/500/500" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:var(--radius)">@endforeach
    </div>
    <div class="mod-values" style="margin-bottom:var(--sp-8)">
        <div class="mod-panel"><div style="font-size:24px">🎯</div><strong>رؤيتنا</strong><p style="color:var(--muted)">أن نكون الوجهة الأولى للتسوّق في المنطقة.</p></div>
        <div class="mod-panel"><div style="font-size:24px">💎</div><strong>قيمنا</strong><p style="color:var(--muted)">الجودة، الأصالة، والثقة في كل تفصيل.</p></div>
        <div class="mod-panel"><div style="font-size:24px">🤝</div><strong>التزامنا</strong><p style="color:var(--muted)">رضا العميل هو أولويتنا القصوى.</p></div>
    </div>
    <div class="mod-statgrid" style="text-align:center">
        <div class="mod-stat"><div class="mod-count">50K+</div><div class="mod-stat__l">عميل سعيد</div></div>
        <div class="mod-stat"><div class="mod-count">120K+</div><div class="mod-stat__l">طلب مكتمل</div></div>
        <div class="mod-stat"><div class="mod-count">8K+</div><div class="mod-stat__l">منتج</div></div>
        <div class="mod-stat"><div class="mod-count">4.9★</div><div class="mod-stat__l">متوسط التقييم</div></div>
    </div>
</div>
@endsection
