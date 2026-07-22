<?php

use App\Models\WavexTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wavex_templates', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('body');
            $table->string('media_type', 32)->nullable()->after('body_html');
            $table->string('media_path', 2048)->nullable()->after('media_type');
            $table->string('media_original_name', 512)->nullable()->after('media_path');
            $table->string('media_mime', 128)->nullable()->after('media_original_name');
        });

        WavexTemplate::query()->whereNull('body_html')->each(function (WavexTemplate $t) {
            $plain = (string) $t->body;
            $t->body_html = '<p>'.htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
            $t->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('wavex_templates', function (Blueprint $table) {
            $table->dropColumn([
                'body_html',
                'media_type',
                'media_path',
                'media_original_name',
                'media_mime',
            ]);
        });
    }
};
