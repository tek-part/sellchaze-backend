<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_themes', function (Blueprint $table) {
            $table->json('draft_settings')->nullable()->after('settings');
            $table->text('draft_custom_css')->nullable()->after('custom_css');
            $table->timestamp('published_at')->nullable()->after('installed_at');
            $table->foreignId('published_by_user_id')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
        });

        DB::table('store_themes')->update([
            'draft_settings' => DB::raw('settings'),
            'draft_custom_css' => DB::raw('custom_css'),
            'published_at' => DB::raw('COALESCE(updated_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('store_themes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropColumn(['draft_settings', 'draft_custom_css', 'published_at']);
        });
    }
};
