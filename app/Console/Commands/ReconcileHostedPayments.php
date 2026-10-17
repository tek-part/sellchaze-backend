<?php

namespace App\Console\Commands;

use App\Models\StorePaymentTransaction;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\HostedPaymentReconciliation;
use App\Services\Commerce\OrderReceipt;
use Illuminate\Console\Command;
use Throwable;

class ReconcileHostedPayments extends Command
{
    protected $signature = 'payments:reconcile-hosted {--limit=50}';

    protected $description = 'Verify pending hosted payments with their provider account.';

    public function handle(HostedPaymentReconciliation $reconciliation): int
    {
        $rows = StorePaymentTransaction::query()->whereIn('gateway', HostedPaymentGateways::PROVIDERS)
            ->whereIn('status', ['redirect_pending', 'pending'])->whereNotNull('provider_reference')
            ->where('created_at', '>=', now()->subSeconds(OrderReceipt::TTL))->orderBy('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        foreach ($rows as $transaction) {
            try {
                $reconciliation->refresh($transaction);
            } catch (Throwable) {
                // No provider payloads or credentials in scheduler output.
                $this->warn('Unable to reconcile payment '.$transaction->id.'.');
            }
            $transaction->touch();
        }

        return self::SUCCESS;
    }
}
