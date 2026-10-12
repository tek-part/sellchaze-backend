<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreTrackingDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreTrackingSettings
{
    public const PROVIDERS = ['facebook', 'tiktok', 'snapchat'];

    public function configuration(Store $store): array
    {
        return array_replace(['version' => 1, 'facebook' => [], 'tiktok' => [], 'snapchat' => [], 'google' => [], 'conversions' => []], $store->tracking_configuration ?? []);
    }

    public function publicConfiguration(Store $store): array
    {
        $config = $this->configuration($store);

        return ['version' => $config['version'], 'facebook' => $config['facebook'], 'tiktok' => $config['tiktok'], 'snapchat' => $config['snapchat'], 'google' => $config['google']];
    }

    public function enabled(Store $store): bool
    {
        $config = $this->configuration($store);

        return count($config['facebook']) + count($config['tiktok']) + count($config['snapchat']) + count($config['google']) > 0
            || collect($config['conversions'])->contains(fn ($row) => $row['enabled']);
    }

    public function validId(string $provider, string $id): bool
    {
        return (bool) preg_match(match ($provider) {
            'facebook' => '/^[1-9][0-9]{4,24}$/D',
            'tiktok' => '/^[A-Z0-9]{8,32}$/D',
            'snapchat' => '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',
            'google' => '/^(?:G|GT)-[A-Z0-9]{5,20}$/D',
            default => '/(?!) /',
        }, $id);
    }

    public function ids(string $provider, array $ids): array
    {
        if (count($ids) > 10) {
            throw ValidationException::withMessages([$provider => 'Use at most ten identifiers.']);
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = is_string($id) ? trim($id) : '';
            if (! $this->validId($provider, $id)) {
                throw ValidationException::withMessages([$provider => 'Enter identifiers only, one per line.']);
            }
            $clean[] = $provider === 'snapchat' ? strtolower($id) : $id;
        }

        return array_values(array_unique($clean));
    }

    public function pixels(Store $store, array $data): array
    {
        $store = $this->change($store, $data['version'], function (&$config, &$credentials) use ($data) {
            foreach (self::PROVIDERS as $provider) {
                $config[$provider] = $this->ids($provider, $data[$provider]);
            }
        });

        return $this->publicConfiguration($store);
    }

    public function google(Store $store, array $data): array
    {
        $store = $this->change($store, $data['version'], function (&$config, &$credentials) use ($data) {
            $config['google'] = $this->ids('google', $data['google']);
        });

        return $this->publicConfiguration($store);
    }

    public function conversions(Store $store): array
    {
        $config = $this->configuration($store);
        $rows = array_map(function ($row) use ($store) {
            unset($row['generation']);
            $latest = StoreTrackingDelivery::where('store_id', $store->id)->where('destination_id', $row['id'])->latest('id')->first();

            return $row + ['token_configured' => isset($store->tracking_credentials[$row['id']]),
                'last_delivery' => $latest ? ['status' => $latest->status, 'error_code' => $latest->error_code, 'delivered_at' => $latest->delivered_at?->toIso8601String()] : null];
        }, $config['conversions']);

        return ['version' => $config['version'], 'rows' => $rows];
    }

    public function saveConversion(Store $store, array $data, ?string $id = null): array
    {
        $store = $this->change($store, $data['version'], function (&$config, &$credentials) use ($data, $id) {
            $rows = $config['conversions'];
            $index = $id === null ? null : array_search($id, array_column($rows, 'id'), true);
            abort_if($id !== null && $index === false, 404);
            if ($id === null && count($rows) >= 30) {
                throw ValidationException::withMessages(['provider' => 'Use at most thirty server pixels.']);
            }
            if (! $this->validId($data['provider'], $data['pixel_id'])) {
                throw ValidationException::withMessages(['pixel_id' => 'Enter a valid pixel identifier.']);
            }
            $pixel = $data['provider'] === 'snapchat' ? strtolower($data['pixel_id']) : $data['pixel_id'];
            foreach ($rows as $row) {
                if ($row['id'] !== $id && $row['provider'] === $data['provider'] && $row['pixel_id'] === $pixel) {
                    throw ValidationException::withMessages(['pixel_id' => 'This server pixel already exists.']);
                }
            }
            $old = $index === null ? null : $rows[$index];
            $key = $id ?? (string) Str::uuid();
            $token = trim($data['token'] ?? '');
            if ($token !== '') {
                $credentials[$key] = $token;
            }
            if (! isset($credentials[$key])) {
                throw ValidationException::withMessages(['token' => 'Enter the pixel access token.']);
            }
            $changed = ! $old || $old['provider'] !== $data['provider'] || $old['pixel_id'] !== $pixel || $token !== '';
            $row = ['id' => $key, 'provider' => $data['provider'], 'pixel_id' => $pixel, 'note' => $data['note'] ?? '',
                'enabled' => (bool) $data['enabled'], 'generation' => $changed ? (string) Str::uuid() : $old['generation']];
            if ($index === null) {
                $rows[] = $row;
            } else {
                $rows[$index] = $row;
            }
            $config['conversions'] = array_values($rows);
        });

        return $this->conversions($store);
    }

    public function removeConversion(Store $store, string $id, int $version): array
    {
        $store = $this->change($store, $version, function (&$config, &$credentials) use ($id) {
            abort_unless(in_array($id, array_column($config['conversions'], 'id'), true), 404);
            $config['conversions'] = array_values(array_filter($config['conversions'], fn ($row) => $row['id'] !== $id));
            unset($credentials[$id]);
        });

        return $this->conversions($store);
    }

    private function change(Store $store, int $version, callable $edit): Store
    {
        return DB::transaction(function () use ($store, $version, $edit) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $config = $this->configuration($store);
            $credentials = $store->tracking_credentials ?? [];
            abort_unless((int) $config['version'] === $version, 409, 'Tracking settings changed. Reload before saving.');
            $edit($config, $credentials);
            $config['version'] = $version + 1;
            $store->update(['tracking_configuration' => $config, 'tracking_credentials' => $credentials ?: null]);

            return $store;
        });
    }
}
