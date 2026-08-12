<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Rbac\UserScope;
use App\Services\Themes\StoreThemeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Encapsulates store creation/update: owner resolution, slug hardening
 * (reserved words, Arabic transliteration, uniqueness), logo/banner files,
 * and subdomain (store_domains) provisioning.
 */
class StoreService
{
    /** Slugs that may never be used for a store (would collide with platform hosts). */
    public const RESERVED_SLUGS = [
        'www', 'api', 'admin', 'app', 'dashboard', 'mail', 'ftp',
    ];

    /** Minimal Arabic -> Latin transliteration so Arabic names produce a usable slug. */
    private const AR_MAP = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ء' => '', 'ؤ' => 'w', 'ئ' => 'y',
        'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'th', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh',
        'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
        'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'h', 'ال' => 'al',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Create a store on behalf of an actor (HTTP path). The owner is resolved
     * from the actor's auth context: admins may target any owner via
     * owner_user_id; a non-admin always owns the resulting store themselves.
     */
    public function create(User $actor, array $data, ?UploadedFile $logo = null, ?UploadedFile $banner = null): Store
    {
        $ownerId = UserScope::isAdmin($actor)
            ? (int) ($data['owner_user_id'] ?? $actor->id)
            : UserScope::effectiveMerchantUserId($actor);

        return $this->persist($ownerId, $this->resolveOwnerType($actor, $ownerId), $data, $logo, $banner);
    }

    /**
     * Create the store for an explicit owner, independent of any auth context.
     * Used by StoreProvisioner during the account lifecycle (where there may be
     * no authenticated request, e.g. admin creating an owner, approval, jobs).
     */
    public function createForOwner(User $owner, array $data, ?UploadedFile $logo = null, ?UploadedFile $banner = null): Store
    {
        $ownerType = $owner->hasRole('Supplier') ? 'supplier' : 'merchant';

        return $this->persist((int) $owner->id, $ownerType, $data, $logo, $banner);
    }

    /**
     * Shared store-creation core: enforces one-owner-one-store, hardens the
     * slug, persists images, and provisions the subdomain + default theme.
     */
    private function persist(int $ownerId, string $ownerType, array $data, ?UploadedFile $logo = null, ?UploadedFile $banner = null): Store
    {
        // One owner = one store. Guard against ever creating a second store for
        // the same owner (belt-and-braces with the DB unique constraint and the
        // StorePolicy cardinality gate). StoreProvisioner re-checks under a lock
        // before reaching here, so this only fires on a genuine duplicate.
        if (Store::query()->where('owner_user_id', $ownerId)->exists()) {
            throw ValidationException::withMessages([
                'store' => [__('This owner already has a store.')],
            ]);
        }

        $store = new Store;
        $store->owner_user_id = $ownerId;
        $store->owner_type = $ownerType;
        $store->is_primary = true;
        $store->name = $data['name'];
        $store->slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $store->description = $data['description'] ?? null;
        $store->email = $data['email'] ?? null;
        $store->phone = $data['phone'] ?? null;
        $store->currency = strtoupper($data['currency'] ?? 'USD');
        $store->status = $data['status'] ?? 'draft';

        if ($logo) {
            $store->logo = $this->storeImage($logo);
        }
        if ($banner) {
            $store->banner = $this->storeImage($banner);
        }

        $store->save();

        // Provisioning dependencies are best-effort here: domain/theme setup failures
        // should not prevent the store row from being created.
        try {
            $this->syncSubdomain($store);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            // Phase 4A: attach the active theme install (no-op if no Default theme yet).
            app(StoreThemeService::class)->installAndActivateDefault($store);
        } catch (\Throwable $e) {
            report($e);
        }

        return $store;
    }

    public function update(Store $store, array $data, ?UploadedFile $logo = null, ?UploadedFile $banner = null): Store
    {
        $slugChanged = false;

        if (array_key_exists('name', $data)) {
            $store->name = $data['name'];
        }
        if (! empty($data['slug'])) {
            $newSlug = $this->uniqueSlug($data['slug'], $store->id);
            $slugChanged = $newSlug !== $store->slug;
            $store->slug = $newSlug;
        }
        if (array_key_exists('description', $data)) {
            $store->description = $data['description'];
        }
        if (array_key_exists('email', $data)) {
            $store->email = $data['email'];
        }
        if (array_key_exists('phone', $data)) {
            $store->phone = $data['phone'];
        }
        if (! empty($data['currency'])) {
            $store->currency = strtoupper($data['currency']);
        }
        foreach (['default_locale', 'timezone', 'tax_enabled', 'tax_rate', 'tax_prices_include', 'shipping_enabled', 'shipping_flat_rate', 'shipping_free_over'] as $field) {
            if (array_key_exists($field, $data)) {
                $store->{$field} = $data[$field];
            }
        }
        if (array_key_exists('supported_locales', $data)) {
            $store->supported_locales = array_values(array_unique($data['supported_locales']));
        }
        if (array_key_exists('supported_currencies', $data)) {
            $store->supported_currencies = array_values(array_unique(array_map('strtoupper', $data['supported_currencies'])));
        }
        if (! empty($data['status'])) {
            $store->status = $data['status'];
        }
        if ($logo) {
            $this->deleteImage($store->logo);
            $store->logo = $this->storeImage($logo);
        }
        if ($banner) {
            $this->deleteImage($store->banner);
            $store->banner = $this->storeImage($banner);
        }

        $store->save();

        if ($slugChanged || $store->primaryDomain()->doesntExist()) {
            $this->syncSubdomain($store);
        }

        return $store;
    }

    /**
     * Provision / refresh the platform subdomain row for a store.
     *
     * Old subdomain hosts are demoted to aliases (kept for 301 redirect history)
     * rather than overwritten. Model saves are used so the resolver cache is
     * invalidated via StoreDomain model events.
     *
     * Two ownership guarantees (BUG #2):
     *
     *  - A slug change only ever touches `type = subdomain` rows. A verified
     *    CUSTOM domain that is primary stays primary, so renaming a store can
     *    never 301 live customers backwards off their own domain.
     *  - The host is never re-pointed across stores. Previously this used
     *    updateOrCreate(['host' => ...]) which matched on host alone and
     *    overwrote store_id — letting one store silently inherit another's alias
     *    row (and its inbound 301 traffic). A host held by a different store is
     *    now left alone and the slug is re-derived instead.
     *
     * Runs in a transaction with the store row locked so concurrent updates
     * cannot produce two primaries or a split assignment.
     */
    public function syncSubdomain(Store $store): StoreDomain
    {
        return DB::transaction(function () use ($store): StoreDomain {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $newHost = $this->availableSubdomainHost($store);

            // A verified custom primary outranks the subdomain: when one exists the
            // subdomain is provisioned as a secondary alias, not promoted.
            $customPrimaryExists = $store->servableDomains()
                ->where('type', 'custom')
                ->where('is_primary', true)
                ->exists();

            $shouldBePrimary = ! $customPrimaryExists;

            // Demote only stale SUBDOMAIN primaries. Custom domains are untouched.
            if ($shouldBePrimary) {
                $store->domains()
                    ->where('type', 'subdomain')
                    ->where('is_primary', true)
                    ->where('host', '!=', $newHost)
                    ->lockForUpdate()
                    ->get()
                    ->each(function (StoreDomain $old): void {
                        $old->is_primary = false;
                        $old->save();
                    });
            }

            $existing = StoreDomain::query()->where('host', $newHost)->lockForUpdate()->first();

            if ($existing !== null) {
                // Only ever mutate a row this store already owns.
                $existing->forceFill([
                    'type' => 'subdomain',
                    'status' => StoreDomain::STATUS_VERIFIED,
                    'is_primary' => $shouldBePrimary,
                ]);
                if ($existing->verified_at === null) {
                    $existing->verified_at = now();
                }
                $existing->save();

                return $existing;
            }

            return StoreDomain::create([
                'store_id' => $store->id,
                'host' => $newHost,
                'type' => 'subdomain',
                'status' => StoreDomain::STATUS_VERIFIED,
                'is_primary' => $shouldBePrimary,
            ]);
        });
    }

    /**
     * The subdomain host for this store's slug, re-deriving the slug if the host
     * is already held by a DIFFERENT store. Guarantees the caller never has to
     * take a host away from another tenant to make progress.
     */
    private function availableSubdomainHost(Store $store): string
    {
        $host = $this->subdomainHostForSlug($store->slug);

        $ownerId = StoreDomain::query()->where('host', $host)->value('store_id');
        if ($ownerId === null || (int) $ownerId === (int) $store->id) {
            return $host;
        }

        // Host belongs to another store: pick a fresh slug and persist it so the
        // store's own identity stays consistent with its host.
        $store->slug = $this->uniqueSlug($store->slug.'-'.Str::lower(Str::random(4)), $store->id);
        $store->save();

        return $this->subdomainHostForSlug($store->slug);
    }

    public function subdomainHostForSlug(string $slug): string
    {
        return $slug.'.'.$this->baseDomain();
    }

    public function baseDomain(): string
    {
        return strtolower((string) config('sellchase.storefront.base_domain', 'sellchaze.com'));
    }

    /**
     * Turn any input into a hardened, DB-unique, non-reserved slug.
     */
    public function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = self::normalizeSlug($base);
        $original = $slug;
        $i = 1;

        while (
            in_array($slug, self::RESERVED_SLUGS, true)
            || Store::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $original.'-'.(++$i);
        }

        return $slug;
    }

    /**
     * Pure slug transform (no DB): transliterate Arabic, slugify, enforce a
     * minimum length, and push reserved words out of the reserved namespace.
     */
    public static function normalizeSlug(string $input): string
    {
        $latin = strtr($input, self::AR_MAP);
        $slug = Str::slug($latin);

        if (strlen($slug) < 2) {
            $slug = $slug === '' ? 'store' : $slug.'-store';
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $slug .= '-store';
        }

        return $slug;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED_SLUGS, true);
    }

    private function resolveOwnerType(User $actor, int $ownerId): string
    {
        $owner = $ownerId === (int) $actor->id ? $actor : User::find($ownerId);

        return ($owner && $owner->hasRole('Supplier')) ? 'supplier' : 'merchant';
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store('stores', 'public');
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
