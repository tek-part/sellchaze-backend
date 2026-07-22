<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row justify-content-center">
        <div class="col-12">
            {{-- Top banner card (Rizz pages-profile style) --}}
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-4 align-self-center mb-3 mb-lg-0">
                            <div class="d-flex align-items-center flex-row flex-wrap">
                                <div class="position-relative me-3">
                                    <img src="{{ user_photo($user->id, 'original', false) }}" alt="" height="120" class="rounded-circle" style="width:120px;height:120px;object-fit:cover;" onerror="this.src='{{ placeholder_image('avatar') }}'">
                                </div>
                                <div>
                                    <h5 class="fw-semibold fs-5 mb-1">{{ $user->name }}</h5>
                                    <p class="mb-0 text-muted fw-medium">{{ '@' . ($user->profile->username ?? '—') }}</p>
                                    @if($user->profile->company)
                                        <p class="mb-0 text-muted small">{{ $user->profile->company }}</p>
                                    @endif
                                    @if($user->profile->country || $user->profile->city)
                                        <p class="mb-0 text-muted small mt-1">
                                            <i class="las la-map-marker-alt me-1"></i>
                                            {{ trim(($user->profile->city ?? '') . ', ' . ($user->profile->country ?? ''), ', ') ?: '—' }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 ms-auto align-self-center">
                            @if(auth()->user()->hasRole('Admin'))
                                <a href="{{ route('profile.get.assign.products', encrypt($user->id)) }}" class="btn btn-primary">
                                    <i class="las la-box me-1"></i> {{ __('Assign Products') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row justify-content-center mt-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h4 class="card-title">{{ __('Personal Information') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body pt-0">
                            @if($user->profile->biography)
                                <p class="text-muted fw-medium mb-3">{{ $user->profile->biography }}</p>
                            @endif
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2"><i class="las la-user me-2 text-secondary align-middle"></i> <b>{{ __('Name') }}</b>: {{ $user->name ?? '—' }}</li>
                                <li class="mb-2"><i class="las la-envelope me-2 text-secondary align-middle"></i> <b>{{ __('Email') }}</b>: <a href="mailto:{{ $user->email }}" class="text-primary">{{ $user->email ?? '—' }}</a></li>
                                <li class="mb-2"><i class="las la-venus-mars me-2 text-secondary align-middle"></i> <b>{{ __('Gender') }}</b>: {{ ($user->profile->gender ?? '') == 'female' ? __('Female') : __('Male') }}</li>
                                @if($user->profile->birthdate)
                                <li class="mb-2"><i class="las la-birthday-cake me-2 text-secondary align-middle"></i> <b>{{ __('Birthdate') }}</b>: {{ $user->profile->birthdate }}</li>
                                @endif
                                @if($user->profile->country)
                                <li class="mb-2"><i class="las la-globe me-2 text-secondary align-middle"></i> <b>{{ __('Country') }}</b>: {{ $user->profile->country }}</li>
                                @endif
                                @if($user->profile->city)
                                <li class="mb-2"><i class="las la-map-marker-alt me-2 text-secondary align-middle"></i> <b>{{ __('City') }}</b>: {{ $user->profile->city }}</li>
                                @endif
                                @if($user->profile->address)
                                <li class="mb-2"><i class="las la-home me-2 text-secondary align-middle"></i> <b>{{ __('Address') }}</b>: {{ $user->profile->address }}</li>
                                @endif
                                @if($user->profile->phone)
                                <li class="mb-2"><i class="las la-phone me-2 text-secondary align-middle"></i> <b>{{ __('Phone') }}</b>: <a href="tel:{{ $user->profile->phone }}" class="text-primary">{{ $user->profile->phone }}</a></li>
                                @endif
                                @if($user->profile->whatsapp)
                                <li class="mb-2"><i class="lab la-whatsapp me-2 text-secondary align-middle"></i> <b>{{ __('WhatsApp') }}</b>: <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $user->profile->whatsapp) }}" target="_blank" class="text-success">{{ $user->profile->whatsapp }}</a></li>
                                @endif
                            </ul>
                            @if(count($social_medias) > 0)
                                <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                                    @foreach($social_medias as $social)
                                        @php
                                            $name = is_object($social) ? ($social->name ?? '') : ($social['name'] ?? '');
                                            $url = is_object($social) ? ($social->url ?? '') : ($social['url'] ?? '');
                                        @endphp
                                        @if($url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-light btn-sm">
                                            <i class="las la-external-link-alt me-1"></i>{{ $name ?: 'Link' }}
                                        </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    @if(auth()->user()->hasRole('Admin'))
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <p class="text-muted mb-3">{{ __('Manage products assigned to this user.') }}</p>
                                <a href="{{ route('profile.get.assign.products', encrypt($user->id)) }}" class="btn btn-primary">
                                    <i class="las la-box me-1"></i> {{ __('Assign Products') }}
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-0">{{ __('Viewing profile.') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
