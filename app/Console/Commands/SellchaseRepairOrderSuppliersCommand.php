<?php

namespace App\Console\Commands;

use App\Models\OrderSuppliers;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SellchaseRepairOrderSuppliersCommand extends Command
{
    protected $signature = 'sellchase:repair-order-suppliers
                            {--merchant= : Merchant users.id (default: config services.sellchase_merchant_user_id)}
                            {--dry-run : List rows without updating}
                            {--all-mismatched : Also fix rows whose customer is not Admin (use with care)}';

    protected $description = 'Set order_suppliers.customer to the store merchant for synced Wigpleasure orders (fixes wrong customer after invitation-context bug)';

    public function handle(): int
    {
        $raw = $this->option('merchant');
        if ($raw === null || $raw === '') {
            $raw = config('services.sellchase_merchant_user_id');
        }

        $merchantId = $this->parsePositiveUserId($raw);
        if ($merchantId === null) {
            $hint = is_string($raw) && $raw !== '' && ! ctype_digit(trim($raw))
                ? sprintf('Invalid --merchant value "%s". Use a numeric users.id only (example: --merchant=2).', $raw)
                : 'Pass --merchant=<numeric_id> (example: php artisan sellchase:repair-order-suppliers --dry-run --merchant=2) or set SELLCHASE_MERCHANT_USER_ID in wigpleasure-laravel-orders/.env.';
            $this->error($hint);
            $this->newLine();
            $this->line('Tip: list merchant ids with: php artisan tinker --execute="echo \\App\\Models\\User::role(\'Merchant\')->get([\'id\',\'email\'])->toJson(JSON_PRETTY_PRINT);"');

            return 1;
        }

        $merchant = User::find($merchantId);
        if (! $merchant || ! $merchant->hasRole('Merchant')) {
            $this->error("User {$merchantId} is not a Merchant.");

            return 1;
        }

        $base = OrderSuppliers::query()
            ->where('customer', '!=', $merchantId)
            ->whereHas('order', function ($q) {
                if (Schema::hasColumn('orders', 'wigpleasure_order_id')) {
                    $q->whereNotNull('wigpleasure_order_id');
                } else {
                    $q->where('code', 'like', 'ORD-%');
                }
            });

        if (! $this->option('all-mismatched')) {
            $base->whereHas('customer', fn ($u) => $u->role('Admin'));
        }

        $rows = (clone $base)->with(['order:id,code', 'customer:id,name'])->get();

        if ($rows->isEmpty()) {
            $this->info('No matching order_suppliers rows to repair.');

            return 0;
        }

        $this->table(
            ['id', 'order_id', 'code', 'old_customer_id', 'old_customer_name'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->order_id,
                $r->order->code ?? '—',
                $r->customer,
                $r->customer?->name ?? '—',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run: no updates performed. Run without --dry-run to apply.');

            return 0;
        }

        $updated = $base->update(['customer' => $merchantId]);
        $this->info("Updated {$updated} row(s); order_suppliers.customer set to merchant {$merchantId} ({$merchant->email}).");

        return 0;
    }

    /**
     * @param  mixed  $raw
     */
    private function parsePositiveUserId($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $n = (int) $raw;

            return $n > 0 ? $n : null;
        }
        $s = trim((string) $raw);
        if ($s === '' || ! ctype_digit($s)) {
            return null;
        }
        $n = (int) $s;

        return $n > 0 ? $n : null;
    }
}
