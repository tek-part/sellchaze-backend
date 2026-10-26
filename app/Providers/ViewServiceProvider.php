<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Blade::directive('datetime', function ($expression) {
            return "<?php echo ($expression)->format('m/d/Y H:i'); ?>";
        });

        // Using class based composers...
        // View::composer('profile', ProfileComposer::class);

        // Using closure based composers...
        // View::composer('dashboard', function ($view) {
        //
        // });
    }
}
