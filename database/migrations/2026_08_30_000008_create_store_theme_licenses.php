<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->after('is_marketplace');
            $table->string('currency', 3)->default('USD')->after('price');
            $table->string('license_type', 24)->default('free')->after('currency');
            $table->unsignedSmallInteger('support_days')->default(0)->after('license_type');
        });

        Schema::create('store_theme_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acquired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('source', 24)->default('free');
            $table->decimal('price_paid', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('order_reference')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'theme_id']);
            $table->index(['store_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_theme_licenses');
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn(['price', 'currency', 'license_type', 'support_days']);
        });
    }
};
