@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation">
        <ul class="pagination mb-0 flex-wrap justify-content-center gap-1">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link rounded-circle border-0 bg-light text-muted">{!! __('pagination.previous') !!}</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link rounded-circle border border-gray-300 text-gray-700 text-decoration-none" href="{{ $paginator->previousPageUrl() }}" rel="prev">{!! __('pagination.previous') !!}</a>
                </li>
            @endif

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link rounded-circle border border-gray-300 text-gray-700 text-decoration-none" href="{{ $paginator->nextPageUrl() }}" rel="next">{!! __('pagination.next') !!}</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link rounded-circle border-0 bg-light text-muted">{!! __('pagination.next') !!}</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
