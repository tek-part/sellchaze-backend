<?php

namespace App\Services\Orders;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Merchant -> supplier routing rules shared by the external-store sync and the
 * storefront bridge. Bodies were lifted verbatim from OrderSyncService (which now
 * delegates here); category-specific routing stays in OrderSyncService.
 */
class SupplierRoutingService
{
    /**
     * Suppliers the merchant has an accepted partnership with (merchant_supplier pivot).
     *
     * @return int[]
     */
    public function acceptedSupplierIds(?User $merchantUser): array
    {
        if (! $merchantUser) {
            return [];
        }

        return $merchantUser->suppliersAsMerchant()
            ->wherePivot('status', 'accepted')
            ->select('users.id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $ids
     * @return int[]
     */
    public function filterOutAdminSupplierIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Admin'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Config-driven last resort: SELLCHASE_FALLBACK_SUPPLIER_USER_ID, then (when enabled)
     * the first non-Admin Supplier in the database.
     *
     * @param  int[]  $acceptedSupplierIds
     * @return int[]
     */
    public function fallbackSupplierIds(?User $merchantUser, array $acceptedSupplierIds): array
    {
        $fid = (int) config('services.sellchase_fallback_supplier_user_id', 0);
        if ($fid > 0) {
            $u = User::find($fid);
            if ($u && $u->hasRole('Supplier')) {
                $clean = $this->filterOutAdminSupplierIds([$fid]);
                if ($clean === []) {
                    return [];
                }
                $skipInvite = (bool) config('services.sellchase_fallback_supplier_skip_invite_check');
                if ($skipInvite || in_array($fid, $acceptedSupplierIds, true)) {
                    Log::info('SupplierRoutingService: using SELLCHASE_FALLBACK_SUPPLIER_USER_ID', ['supplier_user_id' => $fid]);

                    return $clean;
                }
            }
        }

        if (! config('services.sellchase_sync_use_first_supplier_when_empty')) {
            return [];
        }

        $firstId = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'Supplier'))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Admin'))
            ->orderBy('id')
            ->value('id');

        if ($firstId) {
            Log::warning('SupplierRoutingService: no supplier resolved; using first non-Admin Supplier (SELLCHASE_SYNC_USE_FIRST_SUPPLIER_WHEN_EMPTY)', [
                'supplier_user_id' => $firstId,
                'merchant_id' => $merchantUser?->id,
            ]);

            return [(int) $firstId];
        }

        return [];
    }

    /**
     * Suppliers a merchant-owned order should fan out to when no category routing applies:
     * all accepted non-Admin partners, else the configured fallback, else none (unrouted).
     *
     * @return int[]
     */
    public function resolveForMerchant(?User $merchantUser): array
    {
        $accepted = $this->acceptedSupplierIds($merchantUser);
        $partners = $this->filterOutAdminSupplierIds($accepted);
        if ($partners !== []) {
            return $partners;
        }

        return $this->fallbackSupplierIds($merchantUser, $accepted);
    }
}
