<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'wigpleasure_export_token')->delete();
        Cache::forget('setting_wigpleasure_export_token');
    }

    public function down(): void
    {
        // Irreversible: token storage removed from application.
    }
};
