<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Outbox\OutboxRecorder;
use App\Services\StoreService;
use App\Services\Themes\StoreThemeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardSelfServiceAccountAction
{
    public function __construct(
        private readonly CreateOrganizationAction $organizations,
        private readonly StoreService $stores,
        private readonly StoreThemeService $themes,
        private readonly OutboxRecorder $outbox,
    ) {}

    /** @return array{organization: Organization, store: Store} */
    public function execute(User $owner, string $companyName, string $storeName): array
    {
        return DB::transaction(function () use ($owner, $companyName, $storeName): array {
            $organization = $this->organizations->execute($owner, [
                'name' => $companyName,
                'slug' => $this->organizationSlug($companyName),
                'type' => $owner->isSupplier() ? 'supplier' : 'merchant',
                'default_locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
                'default_currency' => 'USD',
                'timezone' => config('app.timezone', 'UTC'),
            ]);

            // RegistrationApprover provisions the owner's legacy-compatible tenant root. Adopt
            // that same row into the organization so old APIs and the new multi-store contract
            // share one primary store instead of creating divergent stores.
            $store = Store::query()->where('owner_user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $store->forceFill([
                'organization_id' => $organization->id,
                'is_primary' => true,
                'name' => $storeName,
                'currency' => $organization->default_currency,
                'status' => 'draft',
            ])->save();
            $this->stores->syncSubdomain($store);
            $this->themes->installAndActivateDefault($store, $owner->id);
            $this->outbox->record('StoreCreated', 'store', $store->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'owner_user_id' => $owner->id,
                'source' => 'self_service_onboarding',
            ]);

            return ['organization' => $organization, 'store' => $store->fresh()];
        });
    }

    private function organizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';

        return $base.'-'.Str::lower(Str::random(6));
    }
}
