<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'title_ar')) {
                $table->string('title_ar')->nullable()->after('title');
            }
            if (! Schema::hasColumn('articles', 'excerpt_ar')) {
                $table->text('excerpt_ar')->nullable()->after('excerpt');
            }
            if (! Schema::hasColumn('articles', 'content_ar')) {
                $table->longText('content_ar')->nullable()->after('content');
            }
            if (! Schema::hasColumn('articles', 'meta_title_ar')) {
                $table->string('meta_title_ar')->nullable()->after('meta_title');
            }
            if (! Schema::hasColumn('articles', 'meta_description_ar')) {
                $table->string('meta_description_ar')->nullable()->after('meta_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'title_ar',
                'excerpt_ar',
                'content_ar',
                'meta_title_ar',
                'meta_description_ar',
            ]);
        });
    }
};
