<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wavex_contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('wavex_contact_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_group_id')->constrained('wavex_contact_groups')->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('display_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['contact_group_id', 'phone']);
        });

        Schema::table('wavex_campaigns', function (Blueprint $table) {
            $table->foreignId('contact_group_id')->nullable()->after('template_id')->constrained('wavex_contact_groups')->nullOnDelete();
        });

        Schema::table('wavex_settings', function (Blueprint $table) {
            $table->unsignedInteger('default_campaign_delay_seconds')->default(500)->after('last_synced_at');
        });

        DB::table('wavex_settings')->update(['default_campaign_delay_seconds' => 500]);
    }

    public function down(): void
    {
        Schema::table('wavex_campaigns', function (Blueprint $table) {
            $table->dropForeign(['contact_group_id']);
            $table->dropColumn('contact_group_id');
        });

        Schema::table('wavex_settings', function (Blueprint $table) {
            $table->dropColumn('default_campaign_delay_seconds');
        });

        Schema::dropIfExists('wavex_contact_group_members');
        Schema::dropIfExists('wavex_contact_groups');
    }
};
