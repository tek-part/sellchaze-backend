<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentGateway extends Model
{
    protected $fillable = ['name', 'slug'];

    /** @return HasOne<GatewayWallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(GatewayWallet::class, 'gateway_id');
    }

    /** @return HasMany<GatewayTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(GatewayTransaction::class, 'gateway_id');
    }

    /** Icon class for this gateway (iconoir/icofont). */
    public function iconClass(): string
    {
        return match ($this->slug) {
            'paypal' => 'icofont-money-bag',
            'stripe' => 'iconoir-hand-card',
            'bank_transfer' => 'icofont-bank-alt',
            'cod' => 'icofont-vehicle-delivery-van',
            default => 'iconoir-wallet',
        };
    }

    /** Optional logo path (if exists). */
    public function logoPath(): ?string
    {
        $path = "rizz/images/gateways/{$this->slug}.png";
        $fullPath = public_path($path);

        return file_exists($fullPath) ? $path : null;
    }
}
