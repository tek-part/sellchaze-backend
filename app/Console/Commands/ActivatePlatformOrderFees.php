<?php

namespace App\Console\Commands;

use App\Services\PlatformWallet;
use App\Services\PlatformWalletFunding;
use Illuminate\Console\Command;

class ActivatePlatformOrderFees extends Command
{
    protected $signature = 'platform-wallet:activate-order-fees';

    protected $description = 'Record the irreversible new-order billing boundary; existing orders remain exempt.';

    public function handle(PlatformWallet $wallet, PlatformWalletFunding $funding): int
    {
        if (! config('platform_wallet.fees_enabled')) {
            $this->error('Enable PLATFORM_WALLET_FEES_ENABLED only after the merchant funding flow is operational.');

            return self::FAILURE;
        }
        if (! $funding->configured() || config('platform_wallet.fawaterak.test_mode')) {
            $this->error('Configure and verify live Fawaterak wallet funding before activating new-order fees.');

            return self::FAILURE;
        }
        $boundary = $wallet->activateOrderFees();
        $this->info('New-order billing boundary: '.$boundary.'. Existing orders are exempt. Repeating this command keeps the same boundary.');

        return self::SUCCESS;
    }
}
