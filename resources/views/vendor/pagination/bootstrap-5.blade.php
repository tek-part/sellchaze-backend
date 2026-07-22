@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="pagination-nav">
        <style>
            .pagination-nav .page-link { min-width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; }
            .pagination-nav .pagination { gap: 6px; }
            .pagination-nav .page-link:hover:not(.disabled):not(.active) { background-color: var(--bs-primary); color: #fff !important; border-color: var(--bs-primary) !important; }
        </style>
        <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 w-100">
            {{-- Info text --}}
            <div class="order-2 order-sm-1">
                <p class="mb-0 fs-7 text-gray-600">
                    <span class="fw-semibold text-gray-800">{{ $paginator->firstItem() }}</span>
                    {{ __('pagination.to') }}
                    <span class="fw-semibold text-gray-800">{{ $paginator->lastItem() }}</span>
                    {{ __('pagination.of') }}
                    <span class="fw-semibold text-gray-800">{{ $paginator->total() }}</span>
                    {{ __('pagination.results') }}
                </p>
            </div>

            {{-- Pagination --}}
            <div class="order-1 order-sm-2">
                <ul class="pagination mb-0 flex-wrap justify-content-center">
                    {{-- Previous --}}
                    @if ($paginator->onFirstPage())
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link rounded-circle border-0 bg-light text-muted">
                                <i class="fa fa-chevron-left fs-8"></i>
                            </span>
                        </li>
                    @else
                        <li class="page-item">
                            <a class="page-link rounded-circle border border-gray-300 text-gray-700 text-decoration-none" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')">
                                <i class="fa fa-chevron-left fs-8"></i>
                            </a>
                        </li>
                    @endif

                    {{-- Page numbers --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link rounded-circle border-0">{{ $element }}</span>
                            </li>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <li class="page-item active" aria-current="page">
                                        <span class="page-link rounded-circle border-0 bg-primary text-white fw-bold">{{ $page }}</span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link rounded-circle border border-gray-300 text-gray-700 text-decoration-none fw-semibold" href="{{ $url }}">{{ $page }}</a>
                                    </li>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next --}}
                    @if ($paginator->hasMorePages())
                        <li class="page-item">
                            <a class="page-link rounded-circle border border-gray-300 text-gray-700 text-decoration-none" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')">
                                <i class="fa fa-chevron-right fs-8"></i>
                            </a>
                        </li>
                    @else
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link rounded-circle border-0 bg-light text-muted">
                                <i class="fa fa-chevron-right fs-8"></i>
                            </span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </nav>
@endif
