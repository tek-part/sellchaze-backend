<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wavex_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('queued_count')->default(0)->after('sent_count');
        });
    }

    public function down(): void
    {
        Schema::table('wavex_campaigns', function (Blueprint $table) {
            $table->dropColumn('queued_count');
        });
    }
};
