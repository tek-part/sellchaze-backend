<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use App\Services\PlatformWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchantBulkPayments
{
    public const TARGETS = ['pending', 'paid', 'failed'];

    public function preview(Store $store, array $ids, string $target): array
    {
        return DB::transaction(function () use ($store, $ids, $target) {
            return array_map(function ($context) use ($target) {
                $row = $context['order'];
                $transaction = $this->transaction($context);
                $receipt = $this->receipt($context);
                [$state, $reason] = $this->eligibility($context, $target);

                return ['id' => $row->id, 'order_number' => $row->order_number, 'customer_name' => $row->customer_name,
                    'status' => $row->status, 'payment_status' => $row->payment_status, 'payment_method' => $row->payment_method,
                    'amount' => $row->amountDue(), 'currency' => $row->currency, 'transaction_id' => $transaction?->id,
                    'state' => $state, 'reason' => $reason, 'version' => $this->version($context, $target),
                    'receipt' => $receipt ? ['id' => $receipt->id, 'mime' => $receipt->mime, 'reference' => $receipt->reference] : null];
            }, $this->contexts($store, $ids, false));
        });
    }

    public function apply(Store $store, array $data, int $actorId): array
    {
        return DB::transaction(function () use ($store, $data, $actorId) {
            $expected = collect($data['orders'])->keyBy('id');
            $batchHash = hash('sha256', json_encode($expected->sortKeys()->map(fn ($input) => $this->inputHash($input, $data['target']))->all(), JSON_THROW_ON_ERROR));
            $contexts = $this->contexts($store, $expected->keys()->all(), true);
            $prepared = [];
            // Validate the whole review before any writes, including ambiguous response retries.
            foreach ($contexts as $context) {
                $row = $context['order'];
                $input = $expected[$row->id];
                $hash = hash('sha256', $batchHash.':'.$this->inputHash($input, $data['target']));
                $transaction = $this->transaction($context);
                $receipt = $transaction?->metadata['merchant_payment_review'] ?? null;
                if (is_array($receipt) && ($receipt['request_id'] ?? null) === $data['request_id']) {
                    abort_unless(($receipt['actor_id'] ?? null) === $actorId && ($receipt['input_hash'] ?? null) === $hash
                        && ($receipt['target'] ?? null) === $data['target'] && $row->payment_status === $data['target']
                        && $transaction->status === $data['target'], 409, 'This payment review request has already been used with different details.');
                    $prepared[] = [$context, $input, $hash, 'unchanged', null];

                    continue;
                }
                abort_unless(hash_equals($this->version($context, $data['target']), $input['version']), 409, 'Payment details changed. Reload the review before submitting it.');
                [$state, $reason] = $this->eligibility($context, $data['target']);
                if ($state === 'eligible') {
                    $this->validateConfirmation($row, $input, $data['target']);
                }
                $prepared[] = [$context, $input, $hash, $state, $reason];
            }
            $result = ['updated' => 0, 'skipped' => 0, 'unchanged' => 0, 'orders' => []];
            foreach ($prepared as [$context, $input, $hash, $state, $reason]) {
                $row = $context['order'];
                if ($state !== 'eligible') {
                    $result[$state]++;
                    $result['orders'][] = ['id' => $row->id, 'result' => $state, 'reason' => $reason];

                    continue;
                }
                $transaction = $this->transaction($context);
                $target = $data['target'];
                $note = trim((string) ($input['note'] ?? ''));
                if ($target === 'paid') {
                    // Reopen an unsettled offline attempt only inside audited full confirmation.
                    $transaction->update(['status' => 'pending', 'failed_at' => null]);
                    $row->update(['payment_status' => 'pending']);
                    $reference = trim($input['reference']);
                    if ($row->payment_method === 'cod') {
                        app(ManualCashPayment::class)->confirm($row, $reference, (string) $input['amount'], $input['currency'], $actorId, $note ?: null);
                    } else {
                        app(ManualBankPayment::class)->confirm($row, $reference, $actorId, $note ?: null, $this->receipt($context)?->id);
                    }
                } else {
                    $transaction->update(['status' => $target, 'failed_at' => $target === 'failed' ? now() : null]);
                    $row->update(['payment_status' => $target]);
                    $row->statusChanges()->create(['from_status' => $row->status, 'to_status' => $row->status, 'actor_id' => $actorId,
                        'source' => 'merchant_payment_'.$target, 'notes' => $note]);
                    DB::afterCommit(fn () => StoreAnalyticsService::forget($row->store_id));
                }
                $transaction->refresh();
                $metadata = $transaction->metadata ?? [];
                $metadata['merchant_payment_review'] = ['request_id' => $data['request_id'], 'actor_id' => $actorId,
                    'target' => $target, 'input_hash' => $hash, 'at' => now()->toIso8601String()];
                $transaction->update(['metadata' => $metadata]);
                $result['updated']++;
                $result['orders'][] = ['id' => $row->id, 'result' => 'updated', 'reason' => null];
            }

            return $result;
        }, 3);
    }

    private function contexts(Store $store, array $ids, bool $lock): array
    {
        // Match settlement: payment transactions -> storefront orders.
        $query = StorePaymentTransaction::where('store_id', $store->id)->whereIn('store_order_id', $ids)->orderBy('id');
        $transactions = ($lock ? $query->lockForUpdate() : $query)->get();
        $query = StoreOrder::forStore($store)->whereIn('id', $ids)->orderBy('id');
        $orders = ($lock ? $query->lockForUpdate() : $query)->get();
        abort_unless($orders->count() === count($ids), 404);
        foreach ($orders as $order) {
            app(PlatformWallet::class)->assertOrderVisible($order);
        }
        $proofs = DB::table('store_payment_proofs')->where('store_id', $store->id)->whereIn('store_order_id', $ids)->orderByDesc('created_at')->orderByDesc('id')->get();

        return $orders->map(fn ($order) => ['order' => $order, 'transactions' => $transactions->where('store_order_id', $order->id)->values()->all(),
            'proofs' => $proofs->where('store_order_id', $order->id)->values()->all()])->all();
    }

    private function transaction(array $context): ?StorePaymentTransaction
    {
        foreach (array_reverse($context['transactions']) as $transaction) {
            if ($transaction->gateway === $context['order']->payment_method) {
                return $transaction;
            }
        }

        return null;
    }

    private function receipt(array $context): ?\stdClass
    {
        if ($context['order']->payment_method !== 'transfer_receipt') {
            return null;
        }
        foreach ($context['proofs'] as $proof) {
            if ($proof->status === 'pending') {
                return $proof;
            }
        }

        return null;
    }

    private function eligibility(array $context, string $target): array
    {
        $row = $context['order'];
        $transaction = $this->transaction($context);
        if ($row->payment_status === $target && $transaction?->status === $target) {
            return ['unchanged', null];
        }
        if (in_array($row->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true)) {
            return ['skipped', 'order_closed'];
        }
        if (! in_array($row->payment_method, ['cod', 'bank_transfer', 'transfer_receipt'], true)) {
            return ['skipped', 'provider_controls'];
        }
        // A deposit and its remainder are recorded one at a time from the order page.
        if ($row->deposit_total !== null) {
            return ['skipped', 'deposit_order'];
        }
        // A capture recorded on a replaced attempt (needs a refund) never settles the order.
        if ($row->payment_status === 'paid' || collect($context['transactions'])->contains(fn ($t) => ($t->status === 'paid' || $t->paid_at !== null) && ! isset($t->metadata['unapplied']))) {
            return ['skipped', 'settled_payment'];
        }
        if ($transaction === null) {
            return ['skipped', 'missing_transaction'];
        }
        if (bccomp((string) $transaction->amount, $row->amountDue(), 2) !== 0 || strtoupper($transaction->currency) !== strtoupper($row->currency)) {
            return ['skipped', 'amount_changed'];
        }
        if (! in_array($transaction->status, ['pending', 'failed'], true) || ! in_array($row->payment_status, ['pending', 'unpaid', 'failed'], true)) {
            return ['skipped', 'processing_payment'];
        }
        if ($target === 'paid' && $row->payment_method === 'transfer_receipt' && ! $this->receipt($context)) {
            return ['skipped', 'receipt_required'];
        }

        return ['eligible', null];
    }

    private function version(array $context, string $target): string
    {
        return hash_hmac('sha256', json_encode(['target' => $target, 'order' => $context['order']->getAttributes(),
            'transactions' => array_map(fn ($t) => $t->getAttributes(), $context['transactions']),
            'proofs' => $context['proofs']], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function inputHash(array $input, string $target): string
    {
        return hash('sha256', json_encode([$input['id'], $input['version'], $target, trim((string) ($input['reference'] ?? '')),
            (string) ($input['amount'] ?? ''), strtoupper((string) ($input['currency'] ?? '')), (bool) ($input['acknowledged'] ?? false), trim((string) ($input['note'] ?? ''))], JSON_THROW_ON_ERROR));
    }

    private function validateConfirmation(StoreOrder $row, array $input, string $target): void
    {
        $key = 'orders.'.$row->id;
        if ($target === 'paid') {
            if (! ($input['acknowledged'] ?? false) || trim((string) ($input['reference'] ?? '')) === ''
                || ! isset($input['amount'], $input['currency']) || bccomp((string) $input['amount'], $row->amountDue(), 2) !== 0
                || strtoupper($input['currency']) !== strtoupper($row->currency)) {
                throw ValidationException::withMessages([$key => 'Confirm receipt of the full amount and currency, with a reference for each eligible order.']);
            }
        } elseif (trim((string) ($input['note'] ?? '')) === '') {
            throw ValidationException::withMessages([$key => 'Record a reason for this manual payment status.']);
        }
    }
}
