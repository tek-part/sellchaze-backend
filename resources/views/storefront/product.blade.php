@extends('storefront.layout')

@section('content')
    <p class="muted"><a href="/products">← Products</a></p>
    <article class="card">
        @include('storefront.ui-block', ['slot' => 'before_product_gallery'])
        @if($product->imageUrl())<img data-product-gallery src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" style="max-width:100%;height:auto">@endif
        @include('storefront.ui-block', ['slot' => 'after_product_gallery'])
        <h2 style="margin-top:0">{{ $product->name }}</h2>
        <p class="price" style="font-size:20px">{{ $product->price }} {{ $store->currency }}</p>
        @if($product->category)
            <p class="muted">Category: <a href="/categories/{{ $product->category->slug }}">{{ $product->category->name }}</a></p>
        @endif
        @if($product->description)<p>{{ $product->description }}</p>@endif
    </article>
@endsection
