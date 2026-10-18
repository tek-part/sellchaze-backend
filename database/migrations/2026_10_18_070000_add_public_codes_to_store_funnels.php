<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_funnels', 'public_id')) {
            Schema::table('store_funnels', function (Blueprint $table) {
                $table->uuid('public_id')->nullable()->unique();
            });
        }

        // Keep existing titles, publications, sections and timestamps intact.
        DB::table('store_funnels')->whereNull('public_id')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('store_funnels')->where('id', $row->id)->whereNull('public_id')
                    ->update(['public_id' => (string) Str::uuid()]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('store_funnels')->whereNotNull('public_id')->exists()) {
            throw new RuntimeException('Cannot remove funnel codes while funnels reference them.');
        }

        Schema::table('store_funnels', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
