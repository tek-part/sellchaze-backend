<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investment & partnership opportunities: a factory lists itself as open to
 * investment or to a partnership, describing what it offers and what it seeks.
 * Sellchaze reviews, then approved listings appear on a public opportunities
 * board that investors and potential partners browse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('kind', 20);                 // investment | partnership
            $table->string('title', 191);
            $table->text('description');
            $table->decimal('amount_sought', 15, 2)->nullable();
            $table->string('currency', 8)->default('EGP');
            $table->decimal('equity_offered', 5, 2)->nullable(); // percentage
            $table->string('city', 100)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('status', 12)->default('pending'); // pending|approved|rejected|closed
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'kind']);
            $table->index(['user_id', 'status']);
            $table->index('sector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_opportunities');
    }
};
