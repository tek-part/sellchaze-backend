<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assigned_to_user_id');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->foreign('assigned_to_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['assigned_by_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
