<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StorePhoneBlock;
use App\Models\StorePhoneBlockEvent;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhoneBlocking
{
    public function hasActive(Store $store): bool
    {
        return StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('active', true)->exists();
    }

    /** Checkout holds the store row lock, also held by block/unblock operations. */
    public function assertAllowed(Store $store, ?string $phone): void
    {
        if ($phone === null) {
            if (StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('active', true)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter a valid phone number, including its country code for a foreign number.']);
            }

            return;
        }
        if (StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('phone_normalized', $phone)->where('active', true)->lockForUpdate()->first(['id'])) {
            $message = app(LocaleContext::class)->current() === 'ar'
                ? 'لا يمكن لهذا الرقم إنشاء طلبات جديدة في هذا المتجر. تواصل مع المتجر للمساعدة.'
                : 'This phone number cannot place new orders in this store. Contact the store for assistance.';
            throw ValidationException::withMessages(['customer_phone' => $message]);
        }
    }

    public function add(Store $store, string $raw, ?string $note, int $actorId): StorePhoneBlock
    {
        return DB::transaction(function () use ($store, $raw, $note, $actorId) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $country = app(OrderLimits::class)->configured($store)['phone_country'];
            $phone = app(OrderLimits::class)->normalizePhone($raw, $country);
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid local or international phone number.']);
            }
            $existing = StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('phone_normalized', $phone)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->active, 409, 'This number was unblocked. Open its entry and reactivate it after reviewing its current state.');

                return $existing;
            }
            $block = StorePhoneBlock::create(['store_id' => $store->id, 'phone' => trim($raw), 'phone_normalized' => $phone,
                'phone_country' => $country, 'note' => $note, 'active' => true, 'version' => 1]);
            $this->record($block, 'blocked', $actorId);

            return $block;
        });
    }

    public function update(Store $store, int $id, bool $active, ?string $note, int $version, int $actorId): StorePhoneBlock
    {
        return DB::transaction(function () use ($store, $id, $active, $note, $version, $actorId) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $block = StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($block->active === $active && $block->note === $note) {
                return $block;
            }
            abort_unless($block->version === $version, 409, 'This entry changed. Reload and review its current state before updating it.');
            $action = $block->active === $active ? 'note_changed' : ($active ? 'blocked' : 'unblocked');
            $block->update(['active' => $active, 'note' => $note, 'version' => $version + 1]);
            $this->record($block, $action, $actorId);

            return $block;
        });
    }

    private function record(StorePhoneBlock $block, string $action, int $actorId): void
    {
        StorePhoneBlockEvent::create(['store_id' => $block->store_id, 'store_phone_block_id' => $block->id,
            'actor_id' => $actorId, 'action' => $action, 'note' => $block->note, 'version' => $block->version]);
    }
}
