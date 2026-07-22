@auth
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
        <i class="iconoir-home-simple menu-icon"></i>
        <span>{{ __('Dashboard') }}</span>
    </a>
</li>

@if(auth()->user()->hasRole('Merchant'))
<li class="menu-label mt-2"><span>{{ __('Supply network') }}</span></li>
@can('invitations-list')
<li class="nav-item">
    <a class="nav-link {{ request()->is('invitations*') ? 'active' : '' }}" href="{{ route('invitations.index') }}">
        <i class="iconoir-mail menu-icon"></i>
        <span>{{ __('Suppliers & invitations') }}</span>
    </a>
</li>
@endcan
@can('supplier-routings-manage')
<li class="nav-item">
    <a class="nav-link {{ request()->is('supplier-routings*') ? 'active' : '' }}" href="{{ route('supplier-routings.index') }}">
        <i class="iconoir-layers menu-icon"></i>
        <span>{{ __('Store category routing') }}</span>
    </a>
</li>
@endcan
@endif

@canany(['orders-in', 'orders-out', 'orders-create', 'quotations-in', 'quotations-out', 'deals-in', 'deals-out'])
<li class="menu-label mt-2"><span>{{ __('Orders & Quotations') }}</span></li>
@canany(['orders-in', 'orders-out', 'orders-create'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('orders.*') ? 'active' : '' }}" href="#sidebarOrders" data-bs-toggle="collapse" role="button" aria-expanded="{{ Route::is('orders.*') ? 'true' : 'false' }}" aria-controls="sidebarOrders">
        <i class="iconoir-delivery-truck menu-icon"></i>
        <span>{{ __('Orders') }}</span>
    </a>
    <div class="collapse {{ Route::is('orders.*') ? 'show' : '' }}" id="sidebarOrders">
        <ul class="nav flex-column">
            @can('orders-out')
            <li class="nav-item"><a class="nav-link {{ Route::is('orders.out') ? 'active' : '' }}" href="{{ route('orders.out') }}">{{ __('Purchase Orders') }} @if(Route::is('orders.out'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(countOrders('customer')) }}</span>@endif</a></li>
            @endcan
            @can('orders-in')
            <li class="nav-item"><a class="nav-link {{ Route::is('orders.in') ? 'active' : '' }}" href="{{ route('orders.in') }}">{{ __('Sales Orders') }} @if(Route::is('orders.in'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(countOrders('supplier')) }}</span>@endif</a></li>
            @endcan
            @can('orders-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('orders/create') ? 'active' : '' }}" href="{{ route('orders.create') }}">{{ __('Add Order') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['quotations-in', 'quotations-out'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('quotations.*') ? 'active' : '' }}" href="#sidebarQuotations" data-bs-toggle="collapse" role="button" aria-expanded="{{ Route::is('quotations.*') ? 'true' : 'false' }}" aria-controls="sidebarQuotations">
        <i class="iconoir-journal-page menu-icon"></i>
        <span>{{ __('Quotations') }}</span>
    </a>
    <div class="collapse {{ Route::is('quotations.*') ? 'show' : '' }}" id="sidebarQuotations">
        <ul class="nav flex-column">
            @can('quotations-in')
            <li class="nav-item"><a class="nav-link {{ Route::is('quotations.in') ? 'active' : '' }}" href="{{ route('quotations.in') }}">{{ __('Incoming Quotations') }} @if(Route::is('quotations.in'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\Order::whereHas('suppliers', fn($q) => $q->where('customer', Auth::user()->id))->with('suppliers')->count()) }}</span>@endif</a></li>
            @endcan
            @can('quotations-out')
            <li class="nav-item"><a class="nav-link {{ Route::is('quotations.out') ? 'active' : '' }}" href="{{ route('quotations.out') }}">{{ __('Outgoing Quotations') }} @if(Route::is('quotations.out'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\OrderQuotations::where('supplier_user_id', Auth::user()->id)->count()) }}</span>@endif</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['deals-in', 'deals-out'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('deals.*') ? 'active' : '' }}" href="#sidebarDeals" data-bs-toggle="collapse" role="button" aria-expanded="{{ Route::is('deals.*') ? 'true' : 'false' }}" aria-controls="sidebarDeals">
        <i class="iconoir-hand-card menu-icon"></i>
        <span>{{ __('Deals') }}</span>
    </a>
    <div class="collapse {{ Route::is('deals.*') ? 'show' : '' }}" id="sidebarDeals">
        <ul class="nav flex-column">
            @can('deals-out')
            <li class="nav-item"><a class="nav-link {{ Route::is('deals.out') ? 'active' : '' }}" href="{{ route('deals.out') }}">{{ __('Purchase Deals') }} @if(Route::is('deals.out'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\OrderQuotations::where('customer_user_id', Auth::user()->id)->where('status', 'accepted')->count()) }}</span>@endif</a></li>
            @endcan
            @can('deals-in')
            <li class="nav-item"><a class="nav-link {{ Route::is('deals.in') ? 'active' : '' }}" href="{{ route('deals.in') }}">{{ __('Sales Deals') }} @if(Route::is('deals.in'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\OrderQuotations::where('supplier_user_id', Auth::user()->id)->where('status', 'accepted')->count()) }}</span>@endif</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany
@endcanany

@canany(['balance-in', 'balance-out', 'gateways-list', 'suppliers-payments-list'])
<li class="menu-label mt-2"><span>{{ __('Finance') }}</span></li>
@canany(['balance-in', 'balance-out'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('balance.*') ? 'active' : '' }}" href="#sidebarBalance" data-bs-toggle="collapse" role="button" aria-expanded="{{ Route::is('balance.*') ? 'true' : 'false' }}" aria-controls="sidebarBalance">
        <i class="iconoir-wallet menu-icon"></i>
        <span>{{ __('Balance') }}</span>
    </a>
    <div class="collapse {{ Route::is('balance.*') ? 'show' : '' }}" id="sidebarBalance">
        <ul class="nav flex-column">
            @can('balance-out')
            <li class="nav-item"><a class="nav-link {{ Route::is('balance.out') ? 'active' : '' }}" href="{{ route('balance.out') }}">{{ __('Payables') }} @if(Route::is('balance.out'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\OrderQuotations::where('customer_user_id', Auth::user()->id)->where('status', 'accepted')->groupBy('customer_user_id')->count()) }}</span>@endif</a></li>
            @endcan
            @can('balance-in')
            <li class="nav-item"><a class="nav-link {{ Route::is('balance.in') ? 'active' : '' }}" href="{{ route('balance.in') }}">{{ __('Receivables') }} @if(Route::is('balance.in'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\OrderQuotations::where('supplier_user_id', Auth::user()->id)->where('status', 'accepted')->groupBy('customer_user_id')->count()) }}</span>@endif</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@can('gateways-list')
<li class="nav-item">
    <a class="nav-link {{ Route::is('gateways.*') ? 'active' : '' }}" href="{{ route('gateways.index') }}">
        <i class="iconoir-wallet menu-icon"></i>
        <span>{{ __('Payment Gateways') }}</span>
    </a>
</li>
@endcan

@can('suppliers-payments-list')
<li class="nav-item">
    <a class="nav-link {{ request()->is('suppliers*') ? 'active' : '' }}" href="{{ route('suppliers.payments') }}">
        <i class="iconoir-candlestick-chart menu-icon"></i>
        <span>{{ __('Supplier Payments') }}</span>
    </a>
</li>
@endcan
@endcanany

@canany(['products-list', 'products-create', 'categories-list', 'attributes-list', 'attributes-create'])
<li class="menu-label mt-2"><span>{{ __('Catalog') }}</span></li>
<li class="nav-item">
    <a class="nav-link {{ in_array(request()->segment(1), ['products','categories','attributes']) ? 'active' : '' }}" href="#sidebarProducts" data-bs-toggle="collapse" role="button" aria-expanded="{{ in_array(request()->segment(1), ['products','categories','attributes']) ? 'true' : 'false' }}" aria-controls="sidebarProducts">
        <i class="iconoir-shop menu-icon"></i>
        <span>{{ __('Products') }}</span>
    </a>
    <div class="collapse {{ in_array(request()->segment(1), ['products','categories','attributes']) ? 'show' : '' }}" id="sidebarProducts">
        <ul class="nav flex-column">
            @can('products-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('products') ? 'active' : '' }}" href="{{ route('products.index') }}">{{ __('All Products') }} @if(request()->is('products'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\Product::count()) }}</span>@endif</a></li>
            @endcan
            @can('products-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('products/create') ? 'active' : '' }}" href="{{ route('products.create') }}">{{ __('Add Product') }}</a></li>
            @endcan
            @can('categories-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('categories') ? 'active' : '' }}" href="{{ route('categories.index') }}">{{ __('Categories') }} @if(request()->is('categories'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\Category::count()) }}</span>@endif</a></li>
            @endcan
            @can('attributes-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('attributes') ? 'active' : '' }}" href="{{ route('attributes.index') }}">{{ __('Attributes') }} @if(request()->is('attributes'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\Attribute::count()) }}</span>@endif</a></li>
            @endcan
            @can('attributes-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('attributes/create') ? 'active' : '' }}" href="{{ route('attributes.create') }}">{{ __('Add Attribute') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['deliveries-list', 'tickets-list'])
<li class="menu-label mt-2"><span>{{ __('Operations') }}</span></li>
@can('deliveries-list')
<li class="nav-item">
    <a class="nav-link {{ request()->segment(1) === 'deliveries' ? 'active' : '' }}" href="{{ route('deliveries.index') }}">
        <i class="iconoir-delivery-truck menu-icon"></i>
        <span>{{ __('Deliveries') }}</span>
    </a>
</li>
@endcan

@can('tickets-list')
<li class="nav-item">
    <a class="nav-link {{ request()->segment(1) === 'tickets' ? 'active' : '' }}" href="{{ route('tickets.index') }}">
        <i class="iconoir-send-mail menu-icon"></i>
        <span>{{ __('Support Tickets') }}</span>
    </a>
</li>
@endcan
@endcanany

@canany(['users-list', 'users-create', 'invitations-list', 'invitations-send-request'])
<li class="menu-label mt-2"><span>{{ __('Users') }}</span></li>
@canany(['users-list', 'users-create'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('users.*') ? 'active' : '' }}" href="#sidebarUsers" data-bs-toggle="collapse" role="button" aria-expanded="{{ (request()->is('users') || request()->is('users/*')) ? 'true' : 'false' }}" aria-controls="sidebarUsers">
        <i class="iconoir-user menu-icon"></i>
        <span>{{ __('Users') }}</span>
    </a>
    <div class="collapse {{ (request()->is('users') || request()->is('users/*')) ? 'show' : '' }}" id="sidebarUsers">
        <ul class="nav flex-column">
            @can('users-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('users') && !request()->filled('type') ? 'active' : '' }}" href="{{ route('users.index') }}">{{ __('All Users') }} @if(request()->is('users') && !request()->filled('type'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\User::count()) }}</span>@endif</a></li>
            <li class="nav-item"><a class="nav-link {{ request('type') === 'admin' ? 'active' : '' }}" href="{{ route('users.index', ['type' => 'admin']) }}">{{ __('Admins') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request('type') === 'merchant' ? 'active' : '' }}" href="{{ route('users.index', ['type' => 'merchant']) }}">{{ __('Merchants') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request('type') === 'supplier' ? 'active' : '' }}" href="{{ route('users.index', ['type' => 'supplier']) }}">{{ __('Suppliers') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request('type') === 'pending' ? 'active' : '' }}" href="{{ route('users.index', ['type' => 'pending']) }}">{{ __('Pending approval') }}</a></li>
            @endcan
            @can('users-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('users/create') ? 'active' : '' }}" href="{{ route('users.create') }}">{{ __('Add new') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@if(!auth()->user()->hasRole('Merchant'))
@canany(['invitations-list', 'invitations-send-request'])
<li class="nav-item">
    <a class="nav-link {{ (Route::is('invitations.*') || request()->is('register/request')) ? 'active' : '' }}" href="#sidebarInvitations" data-bs-toggle="collapse" role="button" aria-expanded="{{ (request()->segment(1) === 'invitations' || request()->is('register/request')) ? 'true' : 'false' }}" aria-controls="sidebarInvitations">
        <i class="iconoir-mail menu-icon"></i>
        <span>{{ __('Invitations') }}</span>
    </a>
    <div class="collapse {{ (request()->segment(1) === 'invitations' || request()->is('register/request')) ? 'show' : '' }}" id="sidebarInvitations">
        <ul class="nav flex-column">
            @can('invitations-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('invitations') ? 'active' : '' }}" href="{{ route('invitations.index') }}">{{ __('View All') }} @if(request()->is('invitations'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(App\Models\Invitation::count()) }}</span>@endif</a></li>
            @endcan
            @can('invitations-send-request')
            <li class="nav-item"><a class="nav-link {{ request()->is('register/request') ? 'active' : '' }}" href="{{ route('requestInvitation') }}">{{ __('Request an invitation') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany
@endif
@endcanany

@canany(['website-settings-view', 'website-settings-edit', 'articles-list', 'articles-create'])
<li class="menu-label mt-2"><span>{{ __('Website') }}</span></li>
<li class="nav-item">
    <a class="nav-link {{ (request()->is('settings/website') || request()->is('admin/articles*')) ? 'active' : '' }}" href="#sidebarWebsite" data-bs-toggle="collapse" role="button" aria-expanded="{{ (request()->is('settings/website') || request()->is('admin/articles*')) ? 'true' : 'false' }}" aria-controls="sidebarWebsite">
        <i class="iconoir-globe menu-icon"></i>
        <span>{{ __('External Website') }}</span>
    </a>
    <div class="collapse {{ (request()->is('settings/website') || request()->is('admin/articles*')) ? 'show' : '' }}" id="sidebarWebsite">
        <ul class="nav flex-column">
            @canany(['website-settings-view', 'website-settings-edit'])
            <li class="nav-item"><a class="nav-link {{ request()->is('settings/website') ? 'active' : '' }}" href="{{ route('settings.website') }}">{{ __('Website settings') }}</a></li>
            @endcanany
            @can('articles-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('admin/articles') && !request()->is('admin/articles/create') && !request()->segment(3) ? 'active' : '' }}" href="{{ route('admin.articles.index') }}">{{ __('Articles') }}</a></li>
            @endcan
            @can('articles-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('admin/articles/create') ? 'active' : '' }}" href="{{ route('admin.articles.create') }}">{{ __('Add Article') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['roles-list', 'roles-create', 'permissions-list', 'permissions-create', 'settings-view', 'settings-edit'])
<li class="menu-label mt-2"><span>{{ __('System') }}</span></li>
@canany(['roles-list', 'roles-create'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('roles.*') ? 'active' : '' }}" href="#sidebarRoles" data-bs-toggle="collapse" role="button" aria-expanded="{{ request()->segment(1) === 'roles' ? 'true' : 'false' }}" aria-controls="sidebarRoles">
        <i class="iconoir-shield menu-icon"></i>
        <span>{{ __('Roles') }}</span>
    </a>
    <div class="collapse {{ request()->segment(1) === 'roles' ? 'show' : '' }}" id="sidebarRoles">
        <ul class="nav flex-column">
            @can('roles-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('roles') ? 'active' : '' }}" href="{{ route('roles.index') }}">{{ __('View All') }} @if(request()->is('roles'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(\Spatie\Permission\Models\Role::count()) }}</span>@endif</a></li>
            @endcan
            @can('roles-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('roles/create') ? 'active' : '' }}" href="{{ route('roles.create') }}">{{ __('Add Role') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['permissions-list', 'permissions-create'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('permissions.*') ? 'active' : '' }}" href="#sidebarPermissions" data-bs-toggle="collapse" role="button" aria-expanded="{{ request()->segment(1) === 'permissions' ? 'true' : 'false' }}" aria-controls="sidebarPermissions">
        <i class="iconoir-lock menu-icon"></i>
        <span>{{ __('Permissions') }}</span>
    </a>
    <div class="collapse {{ request()->segment(1) === 'permissions' ? 'show' : '' }}" id="sidebarPermissions">
        <ul class="nav flex-column">
            @can('permissions-list')
            <li class="nav-item"><a class="nav-link {{ request()->is('permissions') && !request()->is('permissions/create') ? 'active' : '' }}" href="{{ route('permissions.index') }}">{{ __('View All') }} @if(request()->is('permissions') && !request()->is('permissions/create'))<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ formatNumber(\Spatie\Permission\Models\Permission::count()) }}</span>@endif</a></li>
            @endcan
            @can('permissions-create')
            <li class="nav-item"><a class="nav-link {{ request()->is('permissions/create') ? 'active' : '' }}" href="{{ route('permissions.create') }}">{{ __('Add Permission') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany

@canany(['settings-view', 'settings-edit'])
<li class="nav-item">
    <a class="nav-link {{ Route::is('settings.*') ? 'active' : '' }}" href="#sidebarSettings" data-bs-toggle="collapse" role="button" aria-expanded="{{ request()->is('settings*') ? 'true' : 'false' }}" aria-controls="sidebarSettings">
        <i class="iconoir-settings menu-icon"></i>
        <span>{{ __('Settings') }}</span>
    </a>
    <div class="collapse {{ request()->is('settings*') ? 'show' : '' }}" id="sidebarSettings">
        <ul class="nav flex-column">
            @can('settings-view')
            <li class="nav-item"><a class="nav-link {{ request()->is('settings/general') ? 'active' : '' }}" href="{{ route('settings.general') }}">{{ __('General settings') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->is('settings/email') ? 'active' : '' }}" href="{{ route('settings.email') }}">{{ __('Email settings') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->is('settings/google') ? 'active' : '' }}" href="{{ route('settings.google') }}">{{ __('Google settings') }}</a></li>
            <li class="nav-item"><a class="nav-link {{ request()->is('settings/apis') ? 'active' : '' }}" href="{{ route('settings.apis') }}">{{ __('Store APIs') }}</a></li>
            @endcan
        </ul>
    </div>
</li>
@endcanany
@endcanany
@endauth
