<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financing requests: a factory/merchant asks for funding (to fulfil a large
 * order, buy raw materials in bulk, or expand a production line). Sellchaze
 * reviews and, once approved, publishes it so funders (micro-finance companies,
 * registered investors/individuals) can see and respond.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 8)->default('EGP');
            $table->string('purpose', 20);              // order | materials | equipment | expansion
            $table->unsignedSmallInteger('repayment_months')->nullable();
            $table->boolean('has_confirmed_order')->default(false);
            $table->text('description');
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | funded | closed
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financing_requests');
    }
};
