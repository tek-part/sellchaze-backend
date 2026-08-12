<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Policies\CategoryPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProductPolicy;
use App\Policies\StorePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Store::class => StorePolicy::class,
        Organization::class => OrganizationPolicy::class,
        Product::class => ProductPolicy::class,
        Category::class => CategoryPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
