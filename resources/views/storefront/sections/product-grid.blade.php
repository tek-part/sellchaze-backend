@php($products = $data['products'] ?? [])
<section data-section="product-grid" style="margin-bottom:24px">
    <h3>Products</h3>
    @if(count($products))
        <div class="grid">
            @foreach($products as $p)
                <a class="card" href="/products/{{ $p['slug'] }}">
                    <strong>{{ $p['name'] }}</strong>
                    <div class="price">{{ $p['price'] }} {{ $store->currency }}</div>
                </a>
            @endforeach
        </div>
    @else
        <p class="muted">No products yet.</p>
    @endif
</section>
