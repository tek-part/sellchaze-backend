<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = 'setting_'.$key;

        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            $item = static::where('key', $key)->first();

            return $item ? $item->value : $default;
        });
    }

    /**
     * Set a setting value.
     *
     * @param  mixed  $value
     */
    public static function set(string $key, $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        Cache::forget('setting_'.$key);
    }

    /**
     * Get all settings as key-value array.
     */
    public static function allAsArray(): array
    {
        return Cache::remember('settings_all', 3600, function () {
            return static::pluck('value', 'key')->toArray();
        });
    }

    /**
     * Clear settings cache (e.g. after bulk update).
     */
    public static function clearCache(): void
    {
        Cache::forget('settings_all');
        foreach (static::pluck('key') as $key) {
            Cache::forget('setting_'.$key);
        }
    }
}
