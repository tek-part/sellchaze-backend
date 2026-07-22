@php($product = $data['product'] ?? null)
@if($product)
    <article class="card" data-section="product-details">
        <p class="muted"><a href="/products">← Products</a></p>
        <h2 style="margin-top:0">{{ $product['name'] }}</h2>
        <p class="price" style="font-size:20px">{{ $product['price'] }} {{ $store->currency }}</p>
        @if(!empty($product['category']))
            <p class="muted">Category:
                <a href="/categories/{{ $product['category']['slug'] }}">{{ $product['category']['name'] }}</a>
            </p>
        @endif
        @if(!empty($product['description']))<p>{{ $product['description'] }}</p>@endif
    </article>
@endif
