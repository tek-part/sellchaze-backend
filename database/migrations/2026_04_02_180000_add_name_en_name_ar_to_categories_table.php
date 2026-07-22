<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_en', 255)->nullable();
            $table->string('name_ar', 255)->nullable();
        });

        if (Schema::hasColumn('categories', 'name_en')) {
            foreach (DB::table('categories')->select('id', 'name')->cursor() as $row) {
                $n = (string) ($row->name ?? '');
                $fill = $n !== '' ? $n : '—';
                DB::table('categories')->where('id', $row->id)->update([
                    'name_en' => $fill,
                    'name_ar' => $fill,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_ar']);
        });
    }
};
