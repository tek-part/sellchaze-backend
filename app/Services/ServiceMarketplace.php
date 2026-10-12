<?php

namespace App\Services;

use App\Models\MarketplaceService;
use App\Models\MarketplaceServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ServiceMarketplace
{
    public const CATEGORIES = [
        'moderation' => ['ar' => 'ادارة الصفحات - moderation', 'en' => 'Page moderation'],
        'landing-pages' => ['ar' => 'انشاء صفحات هبوط', 'en' => 'Landing pages'],
        'ugc' => ['ar' => 'ugc', 'en' => 'UGC'],
        'video' => ['ar' => 'صناعة الفيديو', 'en' => 'Video production'],
        'marketing' => ['ar' => 'تسويق', 'en' => 'Marketing'],
        'development' => ['ar' => 'برمجة', 'en' => 'Development'],
        'consulting' => ['ar' => 'استشارات', 'en' => 'Consulting'],
        'store-setup' => ['ar' => 'تهيئة المتاجر', 'en' => 'Store setup'],
        'design' => ['ar' => 'تصميم', 'en' => 'Design'],
        'accounting' => ['ar' => 'خدمات مالية ومحاسبة', 'en' => 'Finance and accounting'],
    ];

    public const CURRENCIES = ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'MAD', 'DZD', 'TND', 'KWD', 'QAR', 'BHD'];

    /** @return Builder<MarketplaceService> */
    public function visible(): Builder
    {
        return MarketplaceService::where('published', true)->whereHas('provider', fn ($q) => $q->where('published', true)
            ->whereHas('owner', fn ($owner) => $owner->where('is_active', true)->where('pending_approval', false)));
    }

    public function provider(MarketplaceServiceProvider $provider, bool $private = false): array
    {
        return ['id' => $provider->id, 'display_name' => $provider->display_name, 'headline' => $provider->headline, 'bio' => $provider->bio, 'country' => $provider->country,
            'skills' => $provider->skills ?? [], 'portfolio' => array_map(fn ($work) => array_merge($work, ['images' => array_map(fn ($path) => $this->imageUrl($path), $work['images'] ?? []), 'image_paths' => $private ? ($work['images'] ?? []) : []]), $provider->portfolio ?? []),
            'avatar_url' => $provider->avatar ? $this->imageUrl($provider->avatar) : null, 'avatar_path' => $private ? $provider->avatar : null,
            'contacts' => $private || $provider->contacts_public ? $provider->contacts : null,
            'contacts_public' => $provider->contacts_public, 'published' => $provider->published, 'version' => $provider->version];
    }

    public function imageUrl(string $path): string
    {
        $parts = explode('/', $path);

        // The merchant API may force a tenant storefront root URL. Media belongs
        // to the dashboard API on the current origin, not that storefront host.
        return '/api/v1/service-marketplace/media/'.$parts[1].'/'.$parts[2];
    }

    public function service(MarketplaceService $service, bool $detail = false, bool $private = false): array
    {
        $provider = $service->provider;
        $result = ['id' => $service->id, 'title' => $service->title, 'category' => $service->category, 'price_minor' => $service->price_minor,
            'currency' => $service->currency, 'rating' => $service->rating, 'ratings_count' => $service->ratings_count,
            'images' => array_map(fn ($path) => $this->imageUrl($path), $service->images ?? []),
            'provider' => $this->provider($provider, $private), 'created_at' => $service->created_at];
        if ($detail || $private) {
            $result['description'] = $service->description;
        }
        if ($private) {
            $result['image_paths'] = $service->images ?? [];
            $result['published'] = $service->published;
            $result['version'] = $service->version;
        }

        return $result;
    }

    public function assertImages(MarketplaceServiceProvider $provider, array $images): void
    {
        foreach ($images as $path) {
            abort_unless(preg_match('#^marketplace-service-media/'.preg_quote($provider->id, '#').'/[a-f0-9-]{36}\.(?:png|jpg|webp)$#', $path)
                && Storage::disk('local')->exists($path), 422, 'Service images must belong to this provider.');
        }
    }
}
