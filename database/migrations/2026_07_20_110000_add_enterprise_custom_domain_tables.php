<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2 — enterprise custom domains.
 *
 * Extends the Sprint 1 lifecycle with certificate detail, DNS/health state and
 * abuse counters on `store_domains`, and adds two new tables:
 *
 *  - store_domain_events       immutable audit trail (append-only, no updated_at)
 *  - store_domain_certificates renewal history, one row per issuance attempt
 *
 * Additive and backwards compatible: every column is nullable or defaulted, and
 * nothing existing is renamed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            // ---- Certificate detail (current state; history lives in store_domain_certificates)
            $table->string('ssl_issuer', 191)->nullable()->after('ssl_provider');
            $table->string('ssl_fingerprint', 191)->nullable()->after('ssl_issuer');
            $table->json('ssl_san')->nullable()->after('ssl_fingerprint');
            $table->unsignedInteger('ssl_renewal_attempts')->default(0)->after('ssl_expires_at');
            $table->timestamp('ssl_last_attempt_at')->nullable()->after('ssl_renewal_attempts');
            $table->string('ssl_last_error', 500)->nullable()->after('ssl_last_attempt_at');

            // ---- DNS / health snapshot, refreshed by the scheduler
            $table->boolean('dns_txt_ok')->default(false)->after('last_error');
            $table->boolean('dns_target_ok')->default(false)->after('dns_txt_ok');
            $table->string('dns_target_type', 10)->nullable()->after('dns_target_ok');
            $table->unsignedTinyInteger('health_score')->nullable()->after('dns_target_type');
            $table->json('health_report')->nullable()->after('health_score');

            // ---- Abuse protection
            $table->unsignedInteger('verification_attempts')->default(0)->after('verification_token');
            $table->timestamp('verification_token_expires_at')->nullable()->after('verification_attempts');
            $table->timestamp('locked_until')->nullable()->after('verification_token_expires_at');

            // Scheduler sweeps select on these.
            $table->index('ssl_expires_at');
            $table->index(['status', 'type']);
        });

        // Append-only audit trail. Rows survive domain deletion (nullOnDelete +
        // denormalised host) so history can never be erased by removing a domain.
        Schema::create('store_domain_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_domain_id')->nullable()->constrained('store_domains')->nullOnDelete();
            $table->string('host', 253)->index();
            $table->string('event', 40)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('system');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 500)->nullable();
            // Immutable: created_at only, never updated.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['store_id', 'created_at']);
            $table->index(['store_domain_id', 'created_at']);
        });

        // One row per issuance/renewal attempt — the certificate history.
        Schema::create('store_domain_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_domain_id')->constrained('store_domains')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('status', 20)->index();
            $table->string('issuer', 191)->nullable();
            $table->string('fingerprint', 191)->nullable();
            $table->json('san')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['store_domain_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_domain_certificates');
        Schema::dropIfExists('store_domain_events');

        Schema::table('store_domains', function (Blueprint $table) {
            $table->dropIndex(['status', 'type']);
            $table->dropIndex(['ssl_expires_at']);
            $table->dropColumn([
                'ssl_issuer', 'ssl_fingerprint', 'ssl_san',
                'ssl_renewal_attempts', 'ssl_last_attempt_at', 'ssl_last_error',
                'dns_txt_ok', 'dns_target_ok', 'dns_target_type',
                'health_score', 'health_report',
                'verification_attempts', 'verification_token_expires_at', 'locked_until',
            ]);
        });
    }
};
