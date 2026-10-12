<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 40);
            $table->string('phone_normalized', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'phone_normalized']);
        });
    }

    public function down(): void
    {
        if (DB::table('phone_newsletter_subscribers')->exists()) {
            throw new RuntimeException('Export and preserve phone newsletter subscribers before rolling back this migration.');
        }
        Schema::dropIfExists('phone_newsletter_subscribers');
    }
};
