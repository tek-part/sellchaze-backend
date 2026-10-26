{!! $store->name !!}

@foreach ($blocks as $block)
@switch($block['type'])
@case('heading')
{!! $block['text'] !!}

@break
@case('text')
{!! trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</p>', '</li>', '</h2>', '</h3>'], "\n", $block['html'])), ENT_QUOTES | ENT_HTML5)) !!}

@break
@case('image')
@if (! empty($block['href'])){!! $block['alt'] ?? '' !!} {!! $block['href'] !!}

@endif
@break
@case('button')
{!! $block['label'] !!}: {!! $block['href'] !!}

@break
@case('products')
@foreach ($block['items'] as $item)
- {!! $item['name'] !!} — {!! $item['price'] !!}: {!! $item['href'] !!}
@endforeach

@break
@case('divider')
----------

@break
@case('coupon')
{!! $ar ? 'كود الخصم' : 'Discount code' !!}: {!! $block['code'] !!}@if (! empty($block['text'])) — {!! $block['text'] !!}@endif


@break
@endswitch
@endforeach
{!! $ar ? 'إلغاء الاشتراك' : 'Unsubscribe' !!}: {!! $unsubscribeUrl !!}
