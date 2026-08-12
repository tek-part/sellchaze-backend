<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $urls = $this->safeCall(fn () => app(StorefrontUrlGenerator::class));

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'owner_user_id' => $this->owner_user_id,
            'owner_type' => $this->owner_type,
            'is_primary' => (bool) $this->is_primary,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo' => $this->logo,
            'logo_url' => $this->safeCall(fn () => $this->logoUrl()),
            'banner' => $this->banner,
            'banner_url' => $this->safeCall(fn () => $this->bannerUrl()),
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'default_locale' => $this->default_locale,
            'supported_locales' => $this->supported_locales ?? [$this->default_locale ?: 'en'],
            'supported_currencies' => $this->supported_currencies ?? [$this->currency ?: 'USD'],
            'timezone' => $this->timezone,
            'tax' => [
                'enabled' => (bool) $this->tax_enabled,
                'rate' => $this->tax_rate,
                'prices_include_tax' => (bool) $this->tax_prices_include,
            ],
            'shipping' => [
                'enabled' => (bool) $this->shipping_enabled,
                'flat_rate' => $this->shipping_flat_rate,
                'free_over' => $this->shipping_free_over,
            ],
            'status' => $this->status,
            'subdomain_host' => $this->safeCall(fn () => $urls->tenantHost($this->resource)),
            // Owner-facing, directly-openable link for the active environment
            // (e.g. http://slug.localhost:8002 in dev, https://slug.sellchase.com
            // in prod) — built by StorefrontUrlGenerator, never from APP_URL.
            'storefront_url' => $this->safeCall(fn () => $urls->url($this->resource)),
            // Canonical public URL a customer would see (custom domain > primary
            // domain > tenant subdomain), always https.
            'public_url' => $this->safeCall(fn () => $urls->publicUrl($this->resource)),
            // The verified custom domain currently serving as canonical, if any.
            'primary_custom_domain' => $this->safeCall(fn () => $urls->customHost($this->resource)),
            // Full domain list (subdomain + custom) when the caller eager-loaded it,
            // so the settings screen can render status without a second request.
            'domains' => StoreDomainResource::collection($this->whenLoaded('domains')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function safeCall(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
