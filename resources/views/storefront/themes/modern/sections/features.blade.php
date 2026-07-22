@php $s = $section['settings'] ?? []; $items = (!empty($s['items']) && is_array($s['items'])) ? $s['items'] : [
    ['icon' => '🚚', 'title' => 'شحن سريع', 'text' => 'توصيل خلال ٤٨ ساعة'],
    ['icon' => '↩', 'title' => 'إرجاع مجاني', 'text' => 'خلال ١٤ يوماً'],
    ['icon' => '🔒', 'title' => 'دفع آمن', 'text' => 'حماية كاملة لبياناتك'],
    ['icon' => '💬', 'title' => 'دعم ٢٤/٧', 'text' => 'نحن هنا لمساعدتك'],
]; @endphp
<section data-section="features" class="mod-features">
    @foreach($items as $it)<div class="mod-feature"><div class="mod-feature__icon">{{ $it['icon'] }}</div><div><strong>{{ $it['title'] }}</strong><div class="mod-feature__text">{{ $it['text'] }}</div></div></div>@endforeach
</section>
