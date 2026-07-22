<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('documents')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
    }
};
