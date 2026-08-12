<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_a_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('organization_b_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('initiator_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_a_id', 'organization_b_id'], 'org_connections_pair_unique');
            $table->index(['organization_a_id', 'status'], 'org_connections_a_status_idx');
            $table->index(['organization_b_id', 'status'], 'org_connections_b_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_connections');
    }
};
