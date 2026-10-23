@auth
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
        <i class="iconoir-home-simple menu-icon"></i>
        <span>{{ __('Dashboard') }}</span>
    </a>
</li>

@if(auth()->user()->hasRole('Merchant'))
<li class="menu-label mt-2"><span>{{ __('Supply network') }}</span></li>
@endif



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
