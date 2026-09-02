<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->string('status', 20)->default('published')->after('version')->index();
            $table->string('bundle_integrity', 128)->nullable()->after('bundle_url');
            $table->unsignedBigInteger('bundle_size')->nullable()->after('bundle_integrity');
            $table->string('manifest_checksum', 64)->nullable()->after('bundle_size');
            $table->foreignId('uploaded_by_user_id')->nullable()->after('manifest_checksum')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('uploaded_by_user_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('theme_version_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_version_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['theme_version_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_version_status_changes');
        Schema::table('theme_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('uploaded_by_user_id');
            $table->dropColumn(['status', 'bundle_integrity', 'bundle_size', 'manifest_checksum']);
        });
    }
};
