<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_wallet_policy', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('legacy_order_id')->nullable();
            $table->timestamp('activated_at')->nullable();
        });
        DB::table('platform_wallet_policy')->insert(['id' => 1]);
    }

    public function down(): void
    {
        if (DB::table('platform_wallet_policy')->whereNotNull('activated_at')->exists()) {
            throw new RuntimeException('An active billing boundary must be preserved. Disable fees without deleting billing history.');
        }
        Schema::dropIfExists('platform_wallet_policy');
    }
};
