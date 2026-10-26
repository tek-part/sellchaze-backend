<?php

use App\Support\Commerce\FulfillmentBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only, kept apart from the schema migration so it can be retried: it walks orders in chunks of
 * 500 and only writes counters that are still zero (see FulfillmentBackfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(FulfillmentBackfill::class)->run(500);
    }

    /** The counters are dropped with their columns by the schema migration's down(). */
    public function down(): void {}
};
