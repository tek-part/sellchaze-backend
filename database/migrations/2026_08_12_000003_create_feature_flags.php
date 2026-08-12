<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled_by_default')->default(false);
            $table->timestamps();
        });

        Schema::create('organization_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_flag_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->json('configuration')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'feature_flag_id'], 'org_feature_flag_unique');
        });

        DB::table('feature_flags')->insert([
            [
                'key' => 'theme_studio_v2',
                'name' => 'Theme Studio v2',
                'description' => 'Advanced visual theme editor and publishing pipeline.',
                'enabled_by_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'procurement_v2',
                'name' => 'Procurement v2',
                'description' => 'Organization-scoped RFQ and quotation workflow.',
                'enabled_by_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_flags');
        Schema::dropIfExists('feature_flags');
    }
};
