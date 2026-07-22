<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('invitation_token', 32)->unique()->nullable();
            $table->text('permissions')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->unsignedBigInteger('sender_user_id'); 
            $table->foreign('sender_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('receiver_user_id')->nullable(); 
            $table->foreign('receiver_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
