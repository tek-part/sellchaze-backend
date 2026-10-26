<?php

namespace App\Services\Commerce;

use App\Models\StoreMediaAsset;
use App\Models\StorePaymentGateway;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Provider identity is shared by merchant settings and the public checkout. */
class PaymentGatewayCatalog
{
    public const VISIBLE_FIELDS = ['country', 'region', 'merchant_id', 'integration_id', 'profile_id', 'entity_id', 'brands', 'payment_options', 'community_id', 'variable_amount_id', 'iban', 'swift_code', 'account_name', 'bank_name', 'merchant_name', 'public_key', 'publishable_key'];

    public static function all(): array
    {
        $definitions = [
            'cod' => ['Cash on delivery', [], 'png'],
            'paymob' => ['Paymob', ['secret_key', 'public_key', 'integration_id', 'hmac_secret', 'region'], 'png'],
            'kashier' => ['Kashier', ['merchant_id', 'api_key', 'api_secret'], 'png'],
            'stripe' => ['Stripe', ['publishable_key', 'secret_key', 'webhook_secret'], 'png'],
            'paypal' => ['PayPal', ['client_id', 'client_secret', 'webhook_id'], 'png'],
            'paytabs' => ['PayTabs', ['profile_id', 'server_key', 'client_key', 'region'], 'png'],
            'moyasar' => ['Moyasar', ['secret_key'], 'png'],
            'paylink' => ['Paylink', ['api_key', 'api_secret'], 'png'],
            'tap' => ['Tap Payments', ['public_key', 'secret_key'], 'png'],
            'tabby' => ['Tabby', ['public_key', 'secret_key', 'merchant_code'], 'png'],
            'taager' => ['Taager', ['api_key'], 'png'],
            'myfatoorah' => ['MyFatoorah', ['api_key', 'country'], 'png'],
            'fawaterak' => ['Fawaterak', ['api_key', 'vendor_key'], 'webp'],
            'xpay' => ['XPay', ['api_key', 'community_id', 'variable_amount_id'], 'png'],
            'ziina' => ['Ziina', ['api_key'], 'svg'],
            'tamara' => ['Tamara', ['api_token', 'notification_token', 'public_key'], 'png'],
            'easykash' => ['EasyKash', ['api_key', 'payment_options', 'hmac_secret'], 'png'],
            'upayments' => ['UPayments', ['api_key', 'api_secret'], 'svg'],
            'transfer_receipt' => ['Transfer with receipt', ['account_name', 'bank_name', 'iban', 'swift_code'], 'png'],
            'fabmisr' => ['FABMISR', ['merchant_id', 'password', 'merchant_name'], 'png'],
            'bank_transfer' => ['Bank transfer', ['account_name', 'bank_name', 'iban', 'swift_code'], 'svg'],
            'fawry' => ['Fawry', ['merchant_code', 'security_key'], 'png'],
            'hyperpay' => ['HyperPay', ['entity_id', 'access_token', 'brands'], 'svg'],
        ];
        $rows = [];
        foreach ($definitions as $slug => [$name, $fields, $extension]) {
            $rows[$slug] = ['name' => $name, 'fields' => $fields, 'logo_url' => '/payments/'.$slug.'.'.$extension,
                'available' => in_array($slug, array_merge(HostedPaymentGateways::PROVIDERS, ['cod', 'bank_transfer', 'transfer_receipt', 'stripe', 'paypal', 'tabby', 'tamara', 'paymob', 'fawry', 'fawaterak', 'tap', 'paytabs', 'hyperpay']), true)];
        }

        return $rows;
    }

    public static function canonical(string $slug): string
    {
        return ['fawaterk' => 'fawaterak', 'my_fatoorah' => 'myfatoorah', 'upay' => 'upayments'][$slug] ?? $slug;
    }

    public static function required(string $slug): array
    {
        return match ($slug) {
            'cod', 'bank_transfer' => [],
            'stripe' => ['secret_key', 'webhook_secret'],
            'paypal' => ['client_id', 'client_secret', 'webhook_id'],
            'tabby' => ['secret_key', 'merchant_code'],
            'tamara' => ['api_token', 'notification_token'],
            'paymob' => ['secret_key', 'public_key', 'integration_id', 'hmac_secret'],
            'fawry' => ['merchant_code', 'security_key'],
            'fawaterak' => ['api_key', 'vendor_key'],
            'tap' => ['secret_key'],
            'paytabs' => ['profile_id', 'server_key'],
            'hyperpay' => ['entity_id', 'access_token'],
            'easykash' => ['api_key'],
            'transfer_receipt' => ['account_name', 'iban'],
            default => self::all()[$slug]['fields'] ?? [],
        };
    }

    /** Transfer with receipt also works with transfer targets alone (e.g. only an InstaPay / e-wallet number). */
    public static function configured(string $slug, array $credentials, ?array $presentation = null): bool
    {
        if ($slug === 'transfer_receipt' && BankTransferInstructions::targets($presentation) !== []) {
            return true;
        }
        foreach (self::required($slug) as $key) {
            if (! is_scalar($credentials[$key] ?? null) || trim((string) $credentials[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    public static function offered(StorePaymentGateway $setting, ?string $currency = null): bool
    {
        if ($currency !== null && in_array($setting->gateway, HostedPaymentGateways::PROVIDERS, true)) {
            try {
                app(RegionalHostedPayments::class)->validate($setting, strtoupper($currency));
            } catch (HttpException) {
                return false;
            }
        }

        return (self::all()[$setting->gateway]['available'] ?? false)
            && self::configured($setting->gateway, $setting->credentials ?? [], $setting->presentation ?? []);
    }

    public static function presentation(StorePaymentGateway $setting): array
    {
        $provider = self::all()[self::canonical($setting->gateway)] ?? ['name' => $setting->gateway, 'logo_url' => null];
        $presentation = $setting->presentation ?? [];
        $logo = $provider['logo_url'];
        $id = $presentation['logo_asset_id'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $asset = StoreMediaAsset::query()->where('store_id', $setting->store_id)->whereKey($id)->first();
            if ($asset && in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                $logo = Storage::disk($asset->disk)->url($asset->path);
                if (! str_starts_with($logo, 'http')) {
                    $logo = url($logo);
                }
            }
        }

        return ['name' => trim((string) ($presentation['name'] ?? '')) ?: $provider['name'],
            'description' => (string) ($presentation['description'] ?? $setting->notes ?? ''),
            'logo_url' => $logo, 'logo_asset_id' => $presentation['logo_asset_id'] ?? null,
            'price_adjustment' => PaymentPriceAdjustment::configuration($presentation['price_adjustment'] ?? null),
            'transfer_targets' => in_array($setting->gateway, BankTransferInstructions::TARGET_METHODS, true) ? BankTransferInstructions::targets($presentation) : []];
    }
}
