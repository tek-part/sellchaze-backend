<?php

namespace App\Services\Commerce\Apps;

use App\Models\Store;
use App\Services\Commerce\StoreShipping;
use App\Services\Storefront\VisitorCurrency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use libphonenumber\PhoneNumberUtil;

/**
 * Accept orders only for chosen delivery countries (allow-list) or refuse chosen ones (block-list).
 * The delivery country is authoritative; the visitor's IP country is an optional second check that
 * uses the server's local GeoIP database and never blocks when the country cannot be determined.
 */
class CountryRestriction implements MerchantAppSettings
{
    private const MESSAGE_AR = 'عذرًا، لا يستقبل هذا المتجر الطلبات من هذه الدولة حاليًا. تواصل مع المتجر إذا كنت بحاجة إلى مساعدة.';

    private const MESSAGE_EN = 'Sorry, this store does not currently accept orders for this country. Contact the store if you need help.';

    public function column(): string
    {
        return 'country_restriction_configuration';
    }

    public function configured(Store $store): array
    {
        return array_replace(['version' => 1, 'enabled' => false, 'mode' => 'allow', 'countries' => [], 'check_ip' => false,
            'message' => ['ar' => '', 'en' => '']], $store->country_restriction_configuration ?? []);
    }

    /** @return list<string> */
    public static function countries(): array
    {
        $regions = PhoneNumberUtil::getInstance()->getSupportedRegions();
        sort($regions);

        return array_values(array_filter($regions, fn ($code) => preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== '001'));
    }

    public function rules(Store $store): array
    {
        return ['enabled' => ['required', 'boolean'], 'mode' => ['required', Rule::in(['allow', 'block'])],
            'countries' => ['present', 'array', 'max:250'], 'countries.*' => ['required', 'string', 'distinct', Rule::in(self::countries())],
            'check_ip' => ['required', 'boolean']] + LocalizedText::rules('message', 300);
    }

    public function normalize(Store $store, array $data): array
    {
        $countries = array_values(array_unique(array_map('strtoupper', $data['countries'])));
        sort($countries);
        if ($data['enabled'] && $countries === []) {
            throw ValidationException::withMessages(['countries' => 'Choose at least one country before enabling the restriction.']);
        }

        return ['enabled' => (bool) $data['enabled'], 'mode' => $data['mode'], 'countries' => $countries,
            'check_ip' => (bool) $data['check_ip'], 'message' => LocalizedText::normalize($data['message'] ?? [])];
    }

    public function enabled(Store $store): bool
    {
        $config = $this->configured($store);

        return $config['enabled'] && $config['countries'] !== [];
    }

    public function meta(Store $store): array
    {
        return ['countries' => self::countries(), 'ip_lookup_available' => is_file(storage_path('app/geoip/dbip-country-lite.mmdb'))];
    }

    public function allows(Store $store, string $country): bool
    {
        $config = $this->configured($store);
        $listed = in_array(strtoupper($country), $config['countries'], true);

        return $config['mode'] === 'allow' ? $listed : ! $listed;
    }

    public function message(Store $store): string
    {
        return LocalizedText::pick($this->configured($store)['message'], self::MESSAGE_AR, self::MESSAGE_EN);
    }

    /** The selected delivery region's country wins over a typed country. */
    public function deliveryCountry(Store $store, array $selection, ?string $typed = null): ?string
    {
        $shipping = app(StoreShipping::class);
        if ($shipping->regionsEnabled($store) && filled($selection['shipping_region_id'] ?? null)) {
            $region = collect($shipping->configuration($store)['regions'])->first(fn (array $row) => ($row['id'] ?? null) === $selection['shipping_region_id']);

            return $region['country'] ?? null;
        }

        return filled($typed) ? strtoupper((string) $typed) : null;
    }

    /** Placement: a shipped order must name an accepted delivery country. */
    public function assertDelivery(Store $store, ?string $country, bool $requiresShipping, bool $required = true): void
    {
        if (! $requiresShipping || ! $this->enabled($store)) {
            return;
        }
        $country = is_string($country) ? strtoupper(trim($country)) : '';
        if ($country === '') {
            if ($required) {
                throw ValidationException::withMessages(['shipping_address.country' => LocalizedText::pick(null, 'اختر دولة التوصيل.', 'Choose your delivery country.')]);
            }

            return;
        }
        if (! $this->allows($store, $country)) {
            throw ValidationException::withMessages(['shipping_address.country' => $this->message($store)]);
        }
    }

    /** Optional visitor check. An unknown IP country is never treated as a violation. */
    public function assertVisitor(Store $store, ?string $ip): void
    {
        if (! $this->enabled($store) || ! $this->configured($store)['check_ip']) {
            return;
        }
        $country = app(VisitorCurrency::class)->country($ip);
        if ($country !== null && ! $this->allows($store, $country)) {
            throw ValidationException::withMessages(['country_restriction' => $this->message($store)]);
        }
    }

    /** What the checkout needs to explain the policy before the shopper submits. */
    public function publicConfiguration(Store $store): ?array
    {
        if (! $this->enabled($store)) {
            return null;
        }
        $config = $this->configured($store);

        return ['mode' => $config['mode'], 'countries' => $config['countries'], 'message' => $this->message($store)];
    }
}
