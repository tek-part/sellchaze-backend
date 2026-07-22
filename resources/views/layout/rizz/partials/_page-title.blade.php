<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
    <h1 class="page-heading d-flex text-dark fw-bold fs-3 flex-column justify-content-center my-0">{{ $title ?? '' }}</h1>
    @if(!empty($breadcrumb))
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb fw-semibold fs-7 my-0 pt-1">
            <li class="breadcrumb-item">
                <a href="{{ route('dashboard') }}" class="text-muted text-hover-primary">{{ __('Home') }}</a>
            </li>
            <li class="breadcrumb-item text-muted">{{ $breadcrumb }}</li>
        </ol>
    </nav>
    @endif
</div>
