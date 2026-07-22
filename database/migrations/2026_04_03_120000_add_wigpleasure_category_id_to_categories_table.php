<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('wigpleasure_category_id')->nullable()->after('id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('wigpleasure_category_id', 'categories_wigpleasure_category_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_wigpleasure_category_id_unique');
            $table->dropColumn('wigpleasure_category_id');
        });
    }
};
