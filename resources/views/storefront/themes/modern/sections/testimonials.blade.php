@php $s = $section['settings'] ?? []; $items = (!empty($s['items']) && is_array($s['items'])) ? $s['items'] : [
    ['name' => 'سارة الف.', 'text' => 'تجربة تسوّق رائعة، المنتجات أصلية والتوصيل سريع.', 'rating' => 5],
    ['name' => 'محمد ع.', 'text' => 'خدمة عملاء ممتازة وأسعار منافسة، أنصح بالتجربة.', 'rating' => 5],
    ['name' => 'نورة ح.', 'text' => 'التغليف احترافي والجودة تفوق التوقعات.', 'rating' => 4],
]; @endphp
<section data-section="testimonials" class="mod-section">
    <div class="mod-section__head"><h2>{{ $s['title'] ?? 'ماذا يقول عملاؤنا' }}</h2></div>
    <div class="mod-tst">@foreach($items as $it)<div class="mod-tstcard"><div class="mod-tstcard__stars">{{ str_repeat('★', (int) ($it['rating'] ?? 5)) }}</div><p>"{{ $it['text'] }}"</p><div class="mod-tstcard__name"><span>👤</span><strong>{{ $it['name'] }}</strong></div></div>@endforeach</div>
</section>
