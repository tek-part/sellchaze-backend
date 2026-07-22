<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce "one owner = one store" at the database level.
 *
 * FAIL-SAFE: before adding the UNIQUE(owner_user_id) constraint, audit existing
 * data. If any owner already has more than one store, ABORT with the affected
 * owner/store ids listed — do NOT delete, merge, or reassign anything. Data
 * remediation is an explicit business decision that must happen first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('stores')
            ->select('owner_user_id', DB::raw('COUNT(*) as store_count'))
            ->groupBy('owner_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $lines = $duplicates->map(function ($row) {
                $ids = DB::table('stores')
                    ->where('owner_user_id', $row->owner_user_id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->implode(', ');

                return "  owner_user_id={$row->owner_user_id} owns {$row->store_count} stores (ids: {$ids})";
            })->implode("\n");

            throw new RuntimeException(
                "Cannot enforce one-store-per-owner: duplicate stores exist.\n".
                "No data was changed. Consolidate these owners to a single store, then re-run:\n".
                $lines
            );
        }

        // Add the unique index FIRST so the foreign key on owner_user_id can rely
        // on it, then drop the now-redundant non-unique index. (MySQL refuses to
        // drop an index that a foreign key still needs, so ordering matters.)
        Schema::table('stores', function (Blueprint $table) {
            $table->unique('owner_user_id');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->index('owner_user_id');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['owner_user_id']);
        });
    }
};
