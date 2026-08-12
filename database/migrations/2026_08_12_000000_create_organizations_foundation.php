<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('legal_name')->nullable();
                $table->string('type', 32)->default('business');
                $table->string('status', 24)->default('active')->index();
                $table->string('country_code', 2)->nullable();
                $table->string('default_locale', 8)->default('ar');
                $table->string('default_currency', 8)->default('EGP');
                $table->string('timezone', 64)->default('Africa/Cairo');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('organization_memberships')) {
            Schema::create('organization_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 24)->default('member');
                $table->string('status', 24)->default('active');
                $table->json('permissions')->nullable();
                $table->json('store_ids')->nullable();
                $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasColumn('stores', 'organization_id')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('stores', 'is_primary')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->boolean('is_primary')->default(false)->after('owner_type');
            });
        }

        // The legacy schema enforced one store per owner. Ownership now belongs to a
        // company and one company may own many stores; v1 keeps resolving the primary.
        // MySQL foreign keys require a supporting index, so create the replacement
        // before removing the legacy unique index. The guards also make a retry safe
        // after a non-transactional MySQL DDL failure.
        if (! $this->indexExists('stores', 'stores_owner_user_id_index')) {
            Schema::table('stores', fn (Blueprint $table) => $table->index('owner_user_id'));
        }
        if ($this->indexExists('stores', 'stores_owner_user_id_unique')) {
            Schema::table('stores', fn (Blueprint $table) => $table->dropUnique('stores_owner_user_id_unique'));
        }
        if (! $this->indexExists('stores', 'stores_organization_id_status_index')) {
            Schema::table('stores', fn (Blueprint $table) => $table->index(['organization_id', 'status']));
        }

        if (! Schema::hasColumn('subscriptions', 'organization_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }
        if (! $this->indexExists('subscriptions', 'subscriptions_organization_id_status_index')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->index(['organization_id', 'status']));
        }

        $now = now();
        DB::table('stores')->whereNull('organization_id')->orderBy('id')->get()->each(function (object $store) use ($now): void {
            $user = DB::table('users')->where('id', $store->owner_user_id)->first();
            if (! $user) {
                return;
            }

            $organizationId = DB::table('organizations')->where('slug', 'organization-'.$user->id)->value('id');
            if (! $organizationId) {
                $organizationId = DB::table('organizations')->insertGetId([
                    'name' => $user->name ?: $store->name,
                    'slug' => 'organization-'.$user->id,
                    'type' => 'business',
                    'status' => 'active',
                    'default_locale' => 'ar',
                    'default_currency' => $store->currency ?: 'EGP',
                    'timezone' => 'Africa/Cairo',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('organization_memberships')->updateOrInsert(
                ['organization_id' => $organizationId, 'user_id' => $user->id],
                [
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            DB::table('stores')->where('id', $store->id)->update([
                'organization_id' => $organizationId,
                'is_primary' => true,
            ]);

            DB::table('subscriptions')->where('user_id', $user->id)->update([
                'organization_id' => $organizationId,
            ]);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $existing): bool => ($existing['name'] ?? null) === $index);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['owner_user_id']);
            $table->unique('owner_user_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('is_primary');
        });

        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
