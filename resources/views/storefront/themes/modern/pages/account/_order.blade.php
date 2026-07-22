@php $badge = ($o['status'] ?? '') === 'delivered' ? 'mod-badge--success' : ''; @endphp
<a href="/account/orders/{{ $o['number'] }}" class="mod-order">
    <div class="mod-order__thumbs">
        @foreach($o['thumbs'] as $t)
            @if($t)<img src="{{ $t }}" alt="">@endif
        @endforeach
    </div>
    <div style="flex:1;min-width:0"><div class="mod-line__name">#{{ $o['number'] }}</div><div style="color:var(--muted);font-size:14px">{{ $o['date'] }}</div></div>
    <span class="mod-badge {{ $badge }}">{{ $o['label'] }}</span>
    <div class="mod-price">{{ $o['total'] }} {{ $cur ?? 'ر.س' }}</div>
</a>
