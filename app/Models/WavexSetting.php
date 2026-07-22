<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WavexSetting extends Model
{
    protected $fillable = [
        'base_url',
        'access_token_encrypted',
        'instance_id',
        'connection_status',
        'last_synced_at',
        'default_campaign_delay_seconds',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'default_campaign_delay_seconds' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    /**
     * Seconds to wait after each campaign message before the next send.
     * Prefer {@see default_campaign_delay_seconds}; fall back to the campaign row, then 500.
     */
    public static function campaignMessageDelaySeconds(?WavexCampaign $campaign = null): int
    {
        $s = static::current();
        $fromSettings = (int) ($s->default_campaign_delay_seconds ?? 0);
        if ($fromSettings >= 1) {
            return $fromSettings;
        }

        $fromCampaign = $campaign !== null ? (int) $campaign->delay_seconds : 0;

        return max(1, $fromCampaign >= 1 ? $fromCampaign : 500);
    }

    public function assignAccessToken(?string $plain): void
    {
        if ($plain === null || $plain === '') {
            $this->access_token_encrypted = null;

            return;
        }
        $this->access_token_encrypted = encrypt($plain);
    }

    public function getAccessTokenPlain(): ?string
    {
        $v = $this->access_token_encrypted;
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return decrypt($v);
        } catch (\Throwable) {
            return null;
        }
    }

    public function accessTokenMasked(): ?string
    {
        $p = $this->getAccessTokenPlain();
        if ($p === null) {
            return null;
        }
        if (strlen($p) < 8) {
            return '••••••••';
        }

        return substr($p, 0, 4).'…'.substr($p, -4);
    }
}
