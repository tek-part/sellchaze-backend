@php $s = $section['settings'] ?? []; $items = (!empty($s['items']) && is_array($s['items'])) ? $s['items'] : [
    ['n' => '50K+', 'label' => 'عميل سعيد'], ['n' => '120K+', 'label' => 'طلب مكتمل'], ['n' => '8K+', 'label' => 'منتج'], ['n' => '4.9★', 'label' => 'متوسط التقييم'],
]; @endphp
<section data-section="stats" class="mod-stats2">@foreach($items as $it)<div class="mod-stat2"><div class="mod-stat2__n">{{ $it['n'] }}</div><div class="mod-stat2__l">{{ $it['label'] }}</div></div>@endforeach</section>
