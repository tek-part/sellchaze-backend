<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Support\ProductDescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoogleMerchantSettings
{
    public const POLICIES = ['shipping', 'returns', 'cod'];

    public function configuration(Store $store): array
    {
        return array_replace(['version' => 1, 'enabled' => false, 'email' => '', 'phone' => '', 'address' => '', 'cod_disabled' => false,
            'policies' => array_fill_keys(self::POLICIES, ['title' => '', 'content' => ''])], $store->google_merchant_configuration ?? []);
    }

    public function save(Store $store, array $data): array
    {
        $clean = ['enabled' => (bool) $data['enabled'], 'email' => trim($data['email'] ?? ''), 'phone' => trim($data['phone'] ?? ''),
            'address' => trim($data['address'] ?? ''), 'cod_disabled' => (bool) $data['cod_disabled'], 'policies' => []];
        foreach (self::POLICIES as $kind) {
            $policy = $data['policies'][$kind];
            $clean['policies'][$kind] = ['title' => trim($policy['title'] ?? ''), 'content' => ProductDescription::clean($policy['content'] ?? '', true) ?? ''];
        }
        if ($clean['enabled']) {
            $missing = [];
            foreach (['email', 'phone', 'address'] as $field) {
                if ($clean[$field] === '') {
                    $missing[$field] = 'Complete the business contact information before enabling.';
                }
            }
            $digits = preg_replace('/[^0-9]/', '', $clean['phone']);
            if (strlen($digits) < 5 || strlen($digits) > 18) {
                $missing['phone'] = 'Enter a valid business phone number.';
            }
            foreach (self::POLICIES as $kind) {
                if ($kind === 'cod' && $clean['cod_disabled']) {
                    continue;
                }
                $policy = $clean['policies'][$kind];
                if ($policy['title'] === '' || preg_replace('/[\s\x{00a0}]+/u', '', html_entity_decode(strip_tags($policy['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
                    $missing['policies.'.$kind.'.content'] = 'Enter a policy title and content before enabling.';
                }
            }
            if ($missing) {
                throw ValidationException::withMessages($missing);
            }
        }

        return DB::transaction(function () use ($store, $data, $clean) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = $this->configuration($store);
            abort_unless((int) $current['version'] === (int) $data['version'], 409, 'Merchant settings changed. Reload before saving.');
            $store->update(['google_merchant_configuration' => $clean + ['version' => (int) $data['version'] + 1]]);

            return $this->configuration($store);
        });
    }

    public function publicInformation(Store $store): ?array
    {
        $config = $this->configuration($store);
        if (! $config['enabled']) {
            return null;
        }
        $policies = [];
        foreach (self::POLICIES as $kind) {
            if ($kind === 'cod' && $config['cod_disabled']) {
                continue;
            }
            $row = $config['policies'][$kind];
            $policies[] = ['kind' => $kind, 'title' => $row['title'], 'content_html' => ProductDescription::clean($row['content'], true) ?? '', 'url' => '/policies/'.$kind];
        }

        return ['email' => $config['email'], 'phone' => $config['phone'], 'address' => $config['address'], 'policies' => $policies];
    }

    public function navigation(Store $store, array $navigation): array
    {
        foreach ($this->publicInformation($store)['policies'] ?? [] as $policy) {
            $items = $navigation['footer'] ?? [];
            if (! $this->containsUrl($items, $policy['url'])) {
                $navigation['footer'][] = ['id' => 'merchant-'.$policy['kind'], 'label' => $policy['title'], 'type' => 'internal',
                    'target' => ltrim($policy['url'], '/'), 'url' => $policy['url'], 'automatic_page' => true, 'children' => []];
            }
        }

        return $navigation;
    }

    private function containsUrl(array $items, string $url): bool
    {
        foreach ($items as $item) {
            if (rtrim((string) ($item['url'] ?? ''), '/') === $url || $this->containsUrl($item['children'] ?? [], $url)) {
                return true;
            }
        }

        return false;
    }
}
