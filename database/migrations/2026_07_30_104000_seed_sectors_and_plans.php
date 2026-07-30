<?php

use Database\Seeders\PlansSeeder;
use Database\Seeders\SectorsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds essential reference data — the 8-sector directory taxonomy and the two subscription plans —
 * as part of `migrate`, because the production deploy runs `migrate --force` but NOT `db:seed`.
 * Both seeders are idempotent (updateOrCreate by slug), so this is safe to run on every migrate and
 * simply refreshes labels/SEO copy without duplicating rows. This is reference/config data the app
 * cannot function without (empty directory, no plans), not demo content.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SectorsSeeder())->run();
        (new PlansSeeder())->run();
    }

    public function down(): void
    {
        // Reference data — intentionally not torn down on rollback.
    }
};
