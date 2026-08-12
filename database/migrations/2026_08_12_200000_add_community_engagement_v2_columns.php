<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community engagement v2: post/comment editing carries an edited_at stamp, and
 * hashtag search needs a prefix index on the label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('published_at');
        });

        Schema::table('post_comments', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('body');
        });

        Schema::table('hashtags', function (Blueprint $table) {
            $table->index('label');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });

        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });

        Schema::table('hashtags', function (Blueprint $table) {
            $table->dropIndex(['label']);
        });
    }
};
