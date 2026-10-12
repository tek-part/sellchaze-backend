<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class QuantityOffers
{
    public static function inputRules(string $prefix = ''): array
    {
        return [$prefix.'quantity_offer_id' => ['nullable', 'uuid'],
            $prefix.'quantity_offer_version' => ['required_with:'.$prefix.'quantity_offer_id', 'nullable', 'integer', 'min:1', 'max:2147483647']];
    }

    /** Configured offers are public; live stock and selected variant still govern purchase. */
    public function publicOffers(Product $product): array
    {
        return array_values(array_map(fn (array $offer) => ['id' => $offer['id'], 'quantity' => $offer['quantity'],
            'unit_price' => $offer['unit_price'], 'free_shipping' => $offer['free_shipping'],
            'title' => $this->localized($offer['title']), 'subtitle' => $this->localized($offer['subtitle']),
            'version' => $product->quantity_offers_version],
            array_filter($product->quantity_offers ?? [], fn (array $offer) => $offer['enabled'])));
    }

    /** @return array{price:string,offer:array|null} */
    public function resolve(Product $product, string $basePrice, ?string $id, int $quantity, ?int $version): array
    {
        if ($id === null) {
            return ['price' => $basePrice, 'offer' => null];
        }
        $offer = collect($product->quantity_offers ?? [])->first(fn (array $row) => $row['id'] === strtolower($id) && $row['enabled']);
        if ($offer === null || $version !== $product->quantity_offers_version) {
            throw ValidationException::withMessages(['quantity_offer_id' => 'This offer changed or is no longer available. Refresh your cart before ordering.']);
        }
        if ($quantity < 1 || $quantity > 999 || $quantity % $offer['quantity'] !== 0) {
            throw ValidationException::withMessages(['quantity' => 'Choose a complete quantity offer.']);
        }
        if (bccomp($offer['unit_price'], $basePrice, 2) > 0) {
            throw ValidationException::withMessages(['quantity_offer_id' => 'This offer is not available for the current product option or price.']);
        }

        return ['price' => $offer['unit_price'], 'offer' => ['id' => $offer['id'], 'quantity' => $offer['quantity'],
            'unit_price' => $offer['unit_price'], 'base_price' => $basePrice, 'free_shipping' => $offer['free_shipping'],
            'title' => $this->localized($offer['title']), 'subtitle' => $this->localized($offer['subtitle']), 'version' => $version]];
    }

    /** Keep regular and offer lines separate while preserving existing customization identities. */
    public function lineKey(string $personalizationKey, ?string $id): string
    {
        return $id === null ? $personalizationKey : hash('sha256', $personalizationKey.':offer:'.strtolower($id));
    }

    public function canonical(array $offers): array
    {
        return array_map(fn (array $row) => ['id' => strtolower($row['id']), 'quantity' => (int) $row['quantity'],
            'unit_price' => bcadd((string) $row['unit_price'], '0', 2), 'enabled' => (bool) ($row['enabled'] ?? true),
            'free_shipping' => (bool) ($row['free_shipping'] ?? false),
            'title' => ['ar' => trim($row['title']['ar'] ?? ''), 'en' => trim($row['title']['en'] ?? '')],
            'subtitle' => ['ar' => trim($row['subtitle']['ar'] ?? ''), 'en' => trim($row['subtitle']['en'] ?? '')]], $offers);
    }

    /** Legacy cart coupon previews must not reuse an expired offer's price or shipping promise. */
    public function freeShippingForCart(Cart $cart): bool
    {
        $store = Store::findOrFail($cart->store_id);
        $free = false;
        foreach ($cart->items as $line) {
            if ($line->quantity_offer === null) {
                continue;
            }
            $selection = app(PurchasableSelection::class)->resolve($store, (int) $line->store_product_id, $line->variant_id, false,
                $line->quantity_offer['id'], (int) $line->quantity, $line->quantity_offer['version']);
            if (bccomp($selection['price'], (string) $line->unit_price, 2) !== 0) {
                throw ValidationException::withMessages(['quantity_offer_id' => 'This offer changed. Refresh your cart.']);
            }
            $free = $free || ($selection['product']->digital_type === 'physical' && $selection['offer']['free_shipping']);
        }

        return $free;
    }

    private function localized(array $value): string
    {
        return ($value[app()->getLocale()] ?? '') ?: ($value['en'] ?: $value['ar']);
    }
}
