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
            'owner_user_id' => $this->owner_user_id,
            'owner_type' => $this->owner_type,
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
