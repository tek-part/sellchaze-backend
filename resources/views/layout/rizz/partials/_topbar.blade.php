<div class="topbar d-print-none">
    <div class="container-xxl">
        <nav class="topbar-custom d-flex justify-content-between" id="topbar-custom">
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                <li>
                    <button class="nav-link mobile-menu-btn nav-icon" id="togglemenu" type="button">
                        <i class="iconoir-menu-scale"></i>
                    </button>
                </li>
                <li class="mx-3 welcome-text">
                    <h3 class="mb-0 fw-bold text-truncate">
                        @auth
                            {{ __('Welcome') }}, {{ auth()->user()->name }}!
                        @else
                            {{ __('Welcome') }}!
                        @endauth
                    </h3>
                </li>
            </ul>
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                <li class="dropdown">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <img src="{{ asset('rizz/images/flags/' . (app()->getLocale() === 'ar' ? 'egypt_flag' : 'us_flag') . '.jpg') }}" alt="" class="thumb-sm rounded-circle">
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item {{ app()->getLocale() === 'ar' ? 'active' : '' }}" href="{{ route('locale.switch', 'ar') }}">
                            <img src="{{ asset('rizz/images/flags/egypt_flag.jpg') }}" alt="" height="15" class="me-2">{{ __('Arabic') }}
                        </a>
                        <a class="dropdown-item {{ app()->getLocale() === 'en' ? 'active' : '' }}" href="{{ route('locale.switch', 'en') }}">
                            <img src="{{ asset('rizz/images/flags/us_flag.jpg') }}" alt="" height="15" class="me-2">{{ __('English') }}
                        </a>
                    </div>
                </li>
                <li class="topbar-item">
                    <a class="nav-link nav-icon" href="javascript:void(0);" id="light-dark-mode">
                        <i class="icofont-moon dark-mode"></i>
                        <i class="icofont-sun light-mode"></i>
                    </a>
                </li>
                @auth
                <li class="dropdown topbar-item">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon stop" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <i class="icofont-bell-alt"></i>
                        @php $total_notifs = count($orders_notifications ?? []) + count($quotations_notifications ?? []); @endphp
                        @if($total_notifs > 0)
                            <span class="alert-badge">{{ $total_notifs > 99 ? '99+' : $total_notifs }}</span>
                        @endif
                    </a>
                    @include('layout.rizz.partials._notifications-dropdown')
                </li>
                <li class="dropdown topbar-item">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                        <img src="{{ user_photo() }}" alt="" class="thumb-lg rounded-circle">
                    </a>
                    <div class="dropdown-menu dropdown-menu-end py-0">
                        <div class="d-flex align-items-center dropdown-item py-2 bg-secondary-subtle">
                            <div class="flex-shrink-0">
                                <img src="{{ user_photo() }}" alt="" class="thumb-md rounded-circle">
                            </div>
                            <div class="flex-grow-1 ms-2 text-truncate align-self-center">
                                <h6 class="my-0 fw-medium text-dark fs-13">{{ auth()->user()->name }}</h6>
                                <small class="text-muted mb-0">{{ auth()->user()->email }}</small>
                            </div>
                        </div>
                        <div class="dropdown-divider mt-0"></div>
                        <a class="dropdown-item" href="{{ route('profile.show') }}"><i class="iconoir-user me-1"></i> {{ __('My Profile') }}</a>
                        <div class="dropdown-divider mb-0"></div>
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">@csrf</form>
                        <a class="dropdown-item text-danger" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                            <i class="iconoir-cancel me-1"></i> {{ __('Sign Out') }}
                        </a>
                    </div>
                </li>
                @endauth
            </ul>
        </nav>
    </div>
</div>
