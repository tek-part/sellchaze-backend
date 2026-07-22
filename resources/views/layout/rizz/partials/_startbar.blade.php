<div class="startbar d-print-none">
    <div class="brand">
        <a href="{{ url('/') }}" class="logo">
            <span>
                <img src="{{ asset('logo.png') }}" alt="{{ config('app.name') }}" class="logo-lg" height="36" style="object-fit:contain">
            </span>
        </a>
    </div>
    <div class="startbar-menu">
        <div class="startbar-collapse" id="startbarCollapse" data-simplebar>
            <div class="d-flex align-items-start flex-column w-100">
                <ul class="navbar-nav mb-auto w-100">
                    <li class="menu-label pt-0 mt-0"><span>{{ __('Main Menu') }}</span></li>
                    @include('layout.rizz.partials._menu')
                </ul>
            </div>
        </div>
    </div>
</div>
