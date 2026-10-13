<?php

namespace App\Services\Commerce;

use App\Models\Store;

class StoreSocialLinks
{
    public const LABELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'Twitter', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'snapchat' => 'Snapchat'];

    public function configuration(Store $store): array
    {
        $stored = is_array($store->social_links_configuration) ? $store->social_links_configuration : [];
        $links = is_array($stored['links'] ?? null) ? $stored['links'] : [];

        return ['version' => max(1, (int) ($stored['version'] ?? 1)), 'enabled' => (bool) ($stored['enabled'] ?? false),
            'links' => array_replace(array_fill_keys(array_keys(self::LABELS), ''), array_intersect_key($links, self::LABELS))];
    }

    public function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    public function publicLinks(Store $store): array
    {
        $configuration = $this->configuration($store);
        if (! $configuration['enabled']) {
            return [];
        }
        $links = [];
        foreach (self::LABELS as $key => $label) {
            $url = $configuration['links'][$key] ?? '';
            if (is_string($url) && $url !== '' && $this->validUrl($url)) {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        return $links;
    }
}
