{{--
    Theme 01 "Modern" — section-rendered shell (home/product/category/CMS). Extends
    the shared layout (header/announcement/overlays/footer + tokens) and fills main
    with the page's ordered sections. Mirrors storefront-ssr/themes/modern (Layout +
    sections) so the Blade fallback stays visually identical to the React SSR output.
--}}
@extends('storefront.themes.modern.layout')

@section('content')
    @foreach(($context['page']['sections'] ?? []) as $section)
        @includeIf('storefront.themes.modern.sections.'.$section['type'], ['section' => $section, 'ctx' => $context, 'store' => (object) $context['store']])
    @endforeach
@endsection
