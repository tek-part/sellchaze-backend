<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to', 2048);
            $table->string('subject', 1024)->nullable();
            $table->string('template_key', 128)->nullable();
            $table->string('status', 32)->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('template_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
    }
};
