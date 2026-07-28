<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront engagement capture: newsletter subscribers and contact-form messages.
 * Both are per-store (tenant-scoped) so a merchant sees only their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletter_subscribers')) {
            Schema::create('newsletter_subscribers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('email');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['store_id', 'email']);
            });
        }

        if (! Schema::hasTable('store_contact_messages')) {
            Schema::create('store_contact_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('email');
                $table->string('subject')->nullable();
                $table->text('message');
                $table->boolean('is_read')->default(false);
                $table->timestamps();
                $table->index(['store_id', 'is_read']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_contact_messages');
        Schema::dropIfExists('newsletter_subscribers');
    }
};
