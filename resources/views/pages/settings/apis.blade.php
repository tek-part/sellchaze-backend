<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Store APIs') }}</h4>
                            <p class="mb-0 text-muted small">{{ __('APIs that integrate the store with this dashboard.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-4">
                            <p class="mb-0">{{ session('success') }}</p>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <div class="alert alert-info d-flex align-items-start mb-4">
                        <i class="las la-info-circle me-3 fs-4 mt-1"></i>
                        <div>
                            <h6 class="mb-2">{{ __('Store configuration') }}</h6>
                            <p class="mb-2">{{ __('Each merchant can link their own store using their API key. Orders from the store will go to the suppliers you have added in the Invitations section.') }}</p>
                            <p class="mb-1">{{ __('To connect the store (Wigpleasure) to this dashboard, configure in the store .env file:') }}</p>
                            <ul class="mb-0 ps-3 small">
                                <li><code>SELLCHASE_API_URL</code> = {{ __('This dashboard base URL') }} (e.g. {{ config('app.url') }})</li>
                                <li><code>SELLCHASE_API_KEY</code> = {{ __('Your API key from your profile') }}</li>
                            </ul>
                            <p class="mb-0 mt-2 small text-muted">{{ __('Get your API key from your profile page. Add suppliers via Invitations so they receive orders from your store.') }}</p>
                            <p class="mb-0 mt-2 small text-muted">{{ __('If you use the same API key in both apps’ .env files, set SELLCHASE_MERCHANT_USER_ID on this server to your user id (see profile URL) so orders are not assigned to the admin account.') }}</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('API Name') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('URL') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Auth') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($apis as $api)
                                <tr>
                                    <td class="fw-semibold">{{ $api['name'] }}</td>
                                    <td><span class="badge bg-primary">{{ $api['method'] }}</span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <code class="flex-grow-1 text-break small" id="api-url-{{ $loop->index }}">{{ $api['url'] }}</code>
                                            <button type="button" class="btn btn-sm btn-outline-secondary copy-btn" data-copy-target="api-url-{{ $loop->index }}" title="{{ __('Copy') }}">
                                                <i class="las la-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="small">{{ $api['description'] }}</td>
                                    <td><code class="small">{{ $api['auth'] }}</code></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        <h6 class="mb-2">{{ __('Required headers') }}</h6>
                        <pre class="bg-light p-3 rounded small"><code>X-API-Key: {{ __('Your API key') }}
Accept: application/json
Content-Type: application/json</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('rizz-js')
    <script>
        document.querySelectorAll('.copy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = this.getAttribute('data-copy-target');
                var el = document.getElementById(targetId);
                if (!el) return;
                navigator.clipboard.writeText(el.textContent).then(function() {
                    var icon = btn.querySelector('i');
                    if (icon) {
                        icon.classList.remove('la-copy');
                        icon.classList.add('la-check');
                        setTimeout(function() {
                            icon.classList.remove('la-check');
                            icon.classList.add('la-copy');
                        }, 1500);
                    }
                });
            });
        });
    </script>
    @endpush
</x-default-layout>
