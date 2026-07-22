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
        // Normalize existing usernames: generate slugs for nulls/dupes before applying unique index.
        $rows = DB::table('profiles')->select('id', 'username', 'user_id')->get();
        $seen = [];
        foreach ($rows as $row) {
            $base = $row->username ? Str::slug($row->username, '-') : ('user-' . $row->user_id);
            if ($base === '') {
                $base = 'user-' . $row->user_id;
            }
            $candidate = $base;
            $i = 1;
            while (isset($seen[$candidate])) {
                $candidate = $base . '-' . (++$i);
            }
            $seen[$candidate] = true;
            if ($candidate !== $row->username) {
                DB::table('profiles')->where('id', $row->id)->update(['username' => $candidate]);
            }
        }

        Schema::table('profiles', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });

        // Idempotent: only add the unique index if it doesn't already exist.
        $indexes = collect(DB::select("SHOW INDEX FROM profiles WHERE Key_name = 'profiles_username_unique'"));
        if ($indexes->isEmpty()) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->unique('username');
            });
        }
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique(['username']);
        });
    }
};
