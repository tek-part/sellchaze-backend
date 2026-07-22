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
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string("username")->unique();
            $table->text("biography")->nullable();
            $table->string("photo")->nullable();
            $table->string("company")->nullable();
            $table->string("country")->nullable();
            $table->string("city")->nullable();
            $table->string('address')->nullable();
            $table->string("gender")->default('male');
            $table->string("phone")->nullable();
            $table->string("whatsapp")->nullable();
            $table->date("birthdate")->nullable();
            $table->text("social_media")->nullable();
            $table->text("products")->nullable();
            $table->boolean('active')->default(1);
            $table->boolean('online')->default(0);
            $table->boolean('private')->default(0);
            $table->unsignedBigInteger('user_id'); 
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};