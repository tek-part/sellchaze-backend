const mix = require('laravel-mix');
const path = require('path');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management - Rizz Theme (Minimal)
 |--------------------------------------------------------------------------
 | Metronic/Keenthemes has been replaced with Rizz. This minimal config
 | only builds assets still required by the application.
 */

// Laravel Echo for private channels (if used)
if (require('fs').existsSync(path.resolve('resources/js/echo.js'))) {
    mix.js('resources/js/echo.js', 'public/assets/js/echo.js');
}

// Select2 - copied from dist rather than bundled, so it binds to the global
// jQuery already loaded by layout/rizz/master.blade.php instead of pulling a
// second copy of jQuery into a bundle.
mix.copy('node_modules/select2/dist/js/select2.full.min.js', 'public/assets/select2/js/select2.full.min.js');
mix.copy('node_modules/select2/dist/css/select2.min.css', 'public/assets/select2/css/select2.min.css');

// Project initialiser: decides per-select whether to show a search input.
mix.js('resources/js/select2-init.js', 'public/assets/js/select2-init.js');
