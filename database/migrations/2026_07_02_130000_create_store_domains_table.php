<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('host')->unique();            // full hostname, e.g. nike.sellchase.com
            $table->string('type', 20)->default('subdomain'); // subdomain | custom (custom = Phase 3+)
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_domains');
    }
};
