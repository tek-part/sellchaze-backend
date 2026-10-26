<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G4 (#50): service marketplace freelancer analytics. One row per viewer, target
 * and UTC day (service view, profile view or contact click); the viewer is an
 * HMAC, never a user id, and the provider's own visits are not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('provider_id')->constrained('marketplace_service_providers')->cascadeOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('marketplace_services')->nullOnDelete();
            $table->string('kind', 16);
            // Service id, "channel:service id" for contact clicks, or '' for the profile; part of the dedupe key.
            $table->string('target', 64)->default('');
            $table->char('viewer_hash', 64);
            $table->date('day');
            // Quarter hour of the UTC day (0-95) so reports can group in SQL and still place rows in any timezone.
            $table->unsignedTinyInteger('slot');
            $table->timestamp('occurred_at');
            $table->unique(['provider_id', 'kind', 'target', 'viewer_hash', 'day'], 'mkt_engagements_unique');
            $table->index(['provider_id', 'occurred_at'], 'mkt_engagements_provider_time');
        });
    }

    public function down(): void
    {
        if (DB::table('marketplace_engagements')->exists()) {
            throw new RuntimeException('Preserve recorded marketplace engagement before rollback.');
        }
        Schema::dropIfExists('marketplace_engagements');
    }
};
