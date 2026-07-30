<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a supplier/merchant (user) to one or more sectors. `is_primary` marks the sector used to
 * seed the "your sector first" feed ordering and the supplier's default directory placement; a
 * denormalised `users.primary_sector_id` mirrors the primary link for cheap ordering/filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_sector', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'sector_id']);
            $table->index('sector_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('primary_sector_id')->nullable()->after('parent_user_id')
                ->constrained('sectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_sector_id');
        });
        Schema::dropIfExists('supplier_sector');
    }
};
