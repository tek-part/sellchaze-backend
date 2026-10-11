@php($uiHtml = $uiBlocks['blocks'][$slot] ?? '')
@if(($uiBlocks['enabled'] ?? false) && trim($uiHtml) !== '')
    <div class="store-ui-block" data-ui-block="{{ $slot }}">{!! $uiHtml !!}</div>
@endif
