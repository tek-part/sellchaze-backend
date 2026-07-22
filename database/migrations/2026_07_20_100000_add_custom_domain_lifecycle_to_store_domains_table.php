<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom-domain lifecycle: verification, SSL tracking and diagnostics.
 *
 * Additive and backwards compatible. Every existing row is a platform-provisioned
 * subdomain, so it is backfilled to `verified` — subdomains are owned by the
 * platform and need no ownership proof. Only `type = custom` rows ever start at
 * `pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            // Lifecycle: pending -> verified | rejected | disabled.
            // The resolver serves ONLY `verified` rows (StoreDomainResolver).
            $table->string('status', 20)->default('pending')->after('type');

            // DNS TXT ownership proof. Null for platform subdomains.
            $table->string('verification_token', 64)->nullable()->after('is_primary');
            $table->timestamp('verified_at')->nullable()->after('verification_token');
            $table->timestamp('last_checked_at')->nullable()->after('verified_at');
            $table->string('last_error', 500)->nullable()->after('last_checked_at');

            // SSL is provider-agnostic on purpose: Let's Encrypt, Cloudflare and
            // reverse-proxy on-demand TLS all report through these same columns.
            $table->string('ssl_status', 20)->default('none')->after('last_error');
            $table->string('ssl_provider', 40)->nullable()->after('ssl_status');
            $table->timestamp('ssl_issued_at')->nullable()->after('ssl_provider');
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_issued_at');

            // Attribution for the audit trail (who attached this domain).
            $table->foreignId('created_by_user_id')->nullable()->after('ssl_expires_at')
                ->constrained('users')->nullOnDelete();

            // Hot path: TrustHosts + resolver both filter on status.
            $table->index('status');
            $table->index(['store_id', 'type', 'is_primary'], 'store_domains_store_type_primary_index');
        });

        // Backfill: every pre-existing row is a platform subdomain -> verified.
        DB::table('store_domains')->update([
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('store_domains', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropIndex('store_domains_store_type_primary_index');
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'verification_token',
                'verified_at',
                'last_checked_at',
                'last_error',
                'ssl_status',
                'ssl_provider',
                'ssl_issued_at',
                'ssl_expires_at',
                'created_by_user_id',
            ]);
        });
    }
};
