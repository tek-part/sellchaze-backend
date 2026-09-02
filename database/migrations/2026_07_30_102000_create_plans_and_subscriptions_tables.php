<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal subscription layer for feed-posting limits (no billing gateway yet — a paid plan is
 * activated manually / by an admin for now; real recurring billing is a later integration).
 *
 * `plans.post_limit_monthly` = NULL means unlimited (paid), a number means the monthly cap
 * (free trial = 3). A user with NO subscription row defaults to the free plan, so existing users
 * need no backfill. The monthly usage counter is derived from the posts table, not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();               // free_trial | paid
                $table->string('name_en');
                $table->string('name_ar');
                $table->unsignedInteger('post_limit_monthly')->nullable(); // null = unlimited
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 8)->default('EGP');
                $table->unsignedInteger('trial_days')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
            });
        } else {
            $columns = [
                'post_limit_monthly' => fn (Blueprint $table) => $table->unsignedInteger('post_limit_monthly')->nullable(),
                'price' => fn (Blueprint $table) => $table->decimal('price', 10, 2)->default(0),
                'position' => fn (Blueprint $table) => $table->unsignedInteger('position')->default(0),
            ];

            foreach ($columns as $name => $definition) {
                if (! Schema::hasColumn('plans', $name)) {
                    Schema::table('plans', $definition);
                }
            }
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->enum('status', ['trialing', 'active', 'expired', 'cancelled'])->default('active');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        } else {
            foreach (['started_at', 'ends_at'] as $name) {
                if (! Schema::hasColumn('subscriptions', $name)) {
                    Schema::table('subscriptions', fn (Blueprint $table) => $table->timestamp($name)->nullable());
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
