<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_sectors')) {
            Schema::create('organization_sectors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->unique(['organization_id', 'sector_id']);
            });
        }
        if (! Schema::hasColumn('procurement_requests', 'target_sector_id')) {
            Schema::table('procurement_requests', fn (Blueprint $table) => $table->foreignId('target_sector_id')->nullable()->constrained('sectors')->nullOnDelete());
        }
        if (! Schema::hasColumn('procurement_requests', 'items')) {
            Schema::table('procurement_requests', fn (Blueprint $table) => $table->json('items')->nullable());
        }
        if (! Schema::hasColumn('procurement_requests', 'attachments')) {
            Schema::table('procurement_requests', fn (Blueprint $table) => $table->json('attachments')->nullable());
        }
        if (! Schema::hasTable('procurement_request_suppliers')) {
            Schema::create('procurement_request_suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('procurement_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['procurement_request_id', 'supplier_organization_id'], 'rfq_selected_supplier_unique');
            });
        }
        if (! Schema::hasColumn('procurement_quotes', 'version')) {
            Schema::table('procurement_quotes', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        if (! Schema::hasColumn('procurement_quotes', 'delivery_terms')) {
            Schema::table('procurement_quotes', fn (Blueprint $table) => $table->text('delivery_terms')->nullable());
        }
        if (! Schema::hasColumn('procurement_quotes', 'attachments')) {
            Schema::table('procurement_quotes', fn (Blueprint $table) => $table->json('attachments')->nullable());
        }
        if (! Schema::hasTable('procurement_quote_revisions')) {
            Schema::create('procurement_quote_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('procurement_quote_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('snapshot');
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['procurement_quote_id', 'version']);
            });
        }
        if (! Schema::hasTable('procurement_audit_entries')) {
            Schema::create('procurement_audit_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('procurement_request_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('procurement_quote_id')->nullable()->constrained()->nullOnDelete();
                $table->uuid('procurement_order_id')->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 64);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index(['procurement_request_id', 'created_at'], 'procurement_audit_request_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_audit_entries');
        Schema::dropIfExists('procurement_quote_revisions');
        Schema::table('procurement_quotes', fn (Blueprint $table) => $table->dropColumn(['version', 'delivery_terms', 'attachments']));
        Schema::dropIfExists('procurement_request_suppliers');
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_sector_id');
            $table->dropColumn(['items', 'attachments']);
        });
        Schema::dropIfExists('organization_sectors');
    }
};
