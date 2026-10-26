<?php

namespace App\Services\Stores;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/** Store-only accounts never inherit the legacy Employee role's B2B grants. */
class StoreManagers
{
    public const ROLE = 'StoreManager';

    public const PERMISSIONS = [
        'store.view' => ['ar' => 'عرض المتجر', 'en' => 'View store'],
        'store.orders.manage' => ['ar' => 'إدارة الطلبات', 'en' => 'Manage orders'],
        'store.products.manage' => ['ar' => 'إدارة المنتجات', 'en' => 'Manage products'],
        'store.categories.manage' => ['ar' => 'إدارة الأقسام', 'en' => 'Manage categories'],
        'store.coupons.manage' => ['ar' => 'إدارة الكوبونات', 'en' => 'Manage coupons'],
        'store.gift_cards.manage' => ['ar' => 'إدارة بطاقات الهدايا', 'en' => 'Manage gift cards'],
        'store.reviews.manage' => ['ar' => 'إدارة التقييمات', 'en' => 'Manage reviews'],
        'store.analytics.view' => ['ar' => 'عرض الإحصائيات', 'en' => 'View analytics'],
        'store.themes.manage' => ['ar' => 'إدارة الثيمات وقوائمها', 'en' => 'Manage themes and navigation'],
        'store.pages.manage' => ['ar' => 'إدارة الصفحات ومسارات البيع', 'en' => 'Manage pages and funnels'],
        'store.settings.manage' => ['ar' => 'إدارة إعدادات المتجر والشحن والدفع', 'en' => 'Manage store, shipping and payment settings'],
    ];

    public static function parentOwner(User $manager): ?User
    {
        $parent = $manager->parent()->where('is_active', true)->where('pending_approval', false)->first();

        return $parent && ! $parent->parent_user_id && $parent->hasAnyRole(['Merchant']) ? $parent : null;
    }

    /**
     * Only dedicated store accounts; legacy employees and privileged mixed roles stay untouched.
     *
     * @return Builder<User>
     */
    public static function query(Store $store, bool $trashed = false): Builder
    {
        $query = User::query()->where('parent_user_id', $store->owner_user_id)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', self::ROLE))
            ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', '!=', self::ROLE))
            ->whereDoesntHave('roles.permissions')
            ->whereDoesntHave('permissions', fn (Builder $q) => $q->whereNotIn('name', array_keys(self::PERMISSIONS)));

        return $trashed ? $query->onlyTrashed() : $query;
    }

    public static function serialize(User $manager): array
    {
        $permissions = $manager->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $state = [$manager->id, $manager->parent_user_id, $manager->name, $manager->email,
            (bool) $manager->is_active, $manager->deleted_at?->toISOString(), $manager->getRawOriginal('password'), $permissions];

        return [
            'id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email,
            'is_active' => (bool) $manager->is_active, 'permissions' => $permissions,
            'created_at' => $manager->created_at?->toISOString(), 'deleted_at' => $manager->deleted_at?->toISOString(),
            'version' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
        ];
    }

    public function create(Store $store, User $actor, array $data): array
    {
        return DB::transaction(function () use ($store, $actor, $data) {
            $this->lockOwner($store, $actor);
            $role = Role::findOrCreate(self::ROLE, 'web');
            abort_if($role->permissions()->exists(), 409, 'The dedicated store-manager role must have no inherited grants.');
            $manager = User::create([
                'parent_user_id' => $actor->id, 'name' => $data['name'], 'email' => $data['email'],
                'password' => Hash::make($data['password']), 'is_active' => $data['is_active'] ?? true,
                'pending_approval' => false,
            ]);
            $manager->syncRoles([$role]);
            $manager->syncPermissions($this->permissions($data));
            $this->audit($store, $actor, $manager, 'created');

            return self::serialize($manager->fresh());
        });
    }

    public function update(Store $store, User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($store, $actor, $id, $data) {
            $this->lockOwner($store, $actor);
            $manager = $this->locked($store, $id, $data['version']);
            $permissions = $this->permissions($data);
            $permissionChange = self::serialize($manager)['permissions'] !== $permissions;
            $manager->name = $data['name'];
            if ($manager->email !== $data['email']) {
                $manager->email = $data['email'];
                $manager->email_verified_at = null;
            }
            if (! empty($data['password'])) {
                $manager->password = Hash::make($data['password']);
            }
            $manager->is_active = $data['is_active'];
            $sensitive = $permissionChange || $manager->isDirty(['email', 'password', 'is_active']);
            $changed = $permissionChange || $manager->isDirty();
            if ($changed) {
                $manager->save();
                $manager->syncPermissions($permissions);
                if ($sensitive) {
                    JwtTokenService::fromConfig()->revokeOtherSessions($manager, null);
                }
                $this->audit($store, $actor, $manager, 'updated');
            }

            return self::serialize($manager->fresh());
        });
    }

    public function remove(Store $store, User $actor, int $id, string $version): array
    {
        return DB::transaction(function () use ($store, $actor, $id, $version) {
            $this->lockOwner($store, $actor);
            $manager = $this->locked($store, $id, $version);
            $manager->is_active = false;
            $manager->save();
            JwtTokenService::fromConfig()->revokeOtherSessions($manager, null);
            $manager->delete();
            $this->audit($store, $actor, $manager, 'removed');

            return self::serialize($manager);
        });
    }

    public function restore(Store $store, User $actor, int $id, string $version): array
    {
        return DB::transaction(function () use ($store, $actor, $id, $version) {
            $this->lockOwner($store, $actor);
            $manager = $this->locked($store, $id, $version, true);
            // Restoring history does not silently re-enable access or old sessions.
            $manager->is_active = false;
            $manager->restore();
            $manager->save();
            $this->audit($store, $actor, $manager, 'restored');

            return self::serialize($manager->fresh());
        });
    }

    private function permissions(array $data): array
    {
        $permissions = array_values(array_unique(['store.view', ...$data['permissions']]));
        sort($permissions);

        return $permissions;
    }

    private function lockOwner(Store $store, User $actor): void
    {
        $current = Store::query()->lockForUpdate()->findOrFail($store->id);
        $owner = User::query()->findOrFail($actor->id);
        abort_unless((int) $current->owner_user_id === (int) $owner->id && ! $owner->parent_user_id
            && $owner->is_active && ! $owner->pending_approval && $owner->hasAnyRole(['Merchant']), 403);
    }

    private function locked(Store $store, int $id, string $version, bool $trashed = false): User
    {
        $manager = self::query($store, $trashed)->lockForUpdate()->findOrFail($id);
        abort_unless(hash_equals(self::serialize($manager)['version'], $version), 409, 'This manager changed. Reload before saving.');

        return $manager;
    }

    private function audit(Store $store, User $actor, User $manager, string $event): void
    {
        ActivityLog::create([
            'actor_user_id' => $actor->id, 'action' => 'store.manager.'.$event,
            'subject_type' => User::class, 'subject_id' => $manager->id,
            'properties' => ['store_id' => $store->id, 'permissions' => self::serialize($manager)['permissions'], 'is_active' => (bool) $manager->is_active],
            'created_at' => now(),
        ]);
    }
}
