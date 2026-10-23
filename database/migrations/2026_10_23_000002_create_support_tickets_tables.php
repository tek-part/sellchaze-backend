<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Merchant ↔ platform support tickets. Replaces the removed B2B order tickets: a ticket belongs to a
 * merchant account (and optionally its store), not to an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            // The merchant account that owns the ticket; staff/employees act on its behalf.
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160);
            $table->string('category', 30)->default('general');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open');
            $table->string('last_reply_by', 10)->default('merchant');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('merchant_read_at')->nullable();
            $table->timestamp('support_read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status', 'last_reply_at']);
            $table->index(['status', 'priority', 'last_reply_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'id']);
        });

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            $permissionId = DB::table('permissions')->where('name', 'support-tickets-manage')->where('guard_name', $guard)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => 'support-tickets-manage', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->whereIn('name', ['Admin', 'Manager'])->where('guard_name', $guard)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->where('name', 'support-tickets-manage')->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
