@php($content = $section['settings']['content'] ?? '')
@if($content !== '')
    <section class="card" data-section="rich-text" style="margin-bottom:24px">
        {{ $content }}
    </section>
@endif
