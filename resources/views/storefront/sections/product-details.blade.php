@php($product = $data['product'] ?? null)
@if($product)
    <article class="card" data-section="product-details">
        <p class="muted"><a href="/products">← Products</a></p>
        @include('storefront.ui-block', ['slot' => 'before_product_gallery'])
        @if(!empty($product['image_url']))<img data-product-gallery src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" style="max-width:100%;height:auto">@endif
        @include('storefront.ui-block', ['slot' => 'after_product_gallery'])
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
