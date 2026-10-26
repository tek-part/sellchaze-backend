<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreGiftCard;
use App\Models\StoreGiftCardTransaction;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Gift cards are a tender applied after discounts, delivery, tax and payment adjustments. A card
 * is looked up by a store-bound HMAC of its code (keyed with the application key, like other
 * secrets here), so the code itself is shown once at issue and never stored or logged.
 *
 * Balances move only through {@see StoreGiftCardTransaction} rows written under a row lock: the
 * quote reads, placement redeems, cancellation or refund restores (once per card and order).
 */
class GiftCards
{
    public const METHOD = 'gift_card';

    public const MAX_PER_ORDER = 3;

    public const CODE_LENGTH = 16;

    /** One message for unknown, foreign, disabled, expired, empty and other-currency cards. */
    public const INVALID = 'This gift card is invalid, expired or has no balance.';

    /** Failed lookups allowed per IP and minute across check, quote and checkout. */
    public const MISS_LIMIT = 10;

    /** 32 symbols without 0/O/1/I: 16 characters carry 80 random bits. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @return array<string, list<string>> */
    public static function inputRules(): array
    {
        return ['gift_cards' => ['sometimes', 'array', 'max:'.self::MAX_PER_ORDER], 'gift_cards.*' => ['required', 'string', 'max:40']];
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 4));
    }

    public function digest(int $storeId, string $code): string
    {
        return hash_hmac('sha256', 'gift-card:v1:'.$storeId.':'.self::normalize($code), (string) config('app.key'));
    }

    public function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * @param  array{initial_value:string|int|float,expires_at?:mixed,customer_name?:?string,customer_email?:?string,customer_phone?:?string,note?:?string}  $data
     * @return array{card: StoreGiftCard, code: string}
     */
    public function issue(Store $store, array $data, ?int $actorId, string $source = 'manual'): array
    {
        return DB::transaction(function () use ($store, $data, $actorId, $source) {
            do {
                $code = $this->generate();
                $digest = $this->digest($store->id, $code);
            } while (StoreGiftCard::forStore($store)->where('code_digest', $digest)->exists());
            $value = bcadd((string) $data['initial_value'], '0', 2);
            $card = new StoreGiftCard([
                'store_id' => $store->id, 'code_digest' => $digest, 'last4' => substr($code, -4),
                'initial_value' => $value, 'balance' => $value, 'currency' => strtoupper($store->currency ?: 'USD'),
                'expires_at' => $data['expires_at'] ?? null, 'status' => 'active',
                'customer_name' => $data['customer_name'] ?? null, 'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null, 'note' => $data['note'] ?? null,
                'issued_by' => $actorId, 'source' => $source,
            ]);
            $card->save();
            $this->log($card, null, $value, 'issued', $actorId, null);

            return ['card' => $card, 'code' => self::format($code)];
        });
    }

    /** A card by code in this store, or null. Every miss counts against the caller's IP. */
    public function lookup(Store $store, string $code): ?StoreGiftCard
    {
        $key = 'gift-card-miss:'.(request()->ip() ?? 'local');
        if (RateLimiter::tooManyAttempts($key, self::MISS_LIMIT)) {
            throw ValidationException::withMessages(['gift_cards' => 'Too many gift card attempts. Try again in a minute.']);
        }
        $code = self::normalize($code);
        $card = strlen($code) === self::CODE_LENGTH
            ? StoreGiftCard::forStore($store)->where('code_digest', $this->digest($store->id, $code))->first()
            : null;
        if ($card === null) {
            RateLimiter::hit($key, 60);
        }

        return $card;
    }

    public function usable(StoreGiftCard $card, string $currency): bool
    {
        return $card->status === 'active' && ! $card->isExpired() && bccomp((string) $card->balance, '0', 2) > 0
            && strtoupper($card->currency) === strtoupper($currency);
    }

    /**
     * Resolve the submitted codes in order. With $lock the rows are locked in id order (no
     * deadlock between two checkouts sharing cards) and re-read, so balances are current.
     *
     * @param  array<int, mixed>  $codes
     * @return list<StoreGiftCard>
     */
    public function resolve(Store $store, array $codes, string $currency, bool $lock = false): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($code) => self::normalize((string) $code), $codes), fn (string $code) => $code !== '')));
        if (count($codes) > self::MAX_PER_ORDER) {
            throw ValidationException::withMessages(['gift_cards' => 'Use up to '.self::MAX_PER_ORDER.' gift cards per order.']);
        }
        $cards = [];
        foreach ($codes as $index => $code) {
            $card = $this->lookup($store, $code);
            if ($card === null || ! $this->usable($card, $currency)) {
                throw ValidationException::withMessages(['gift_cards.'.$index => self::INVALID]);
            }
            $cards[] = $card;
        }
        if (! $lock || $cards === []) {
            return $cards;
        }
        $locked = StoreGiftCard::forStore($store)->whereIn('id', array_map(fn (StoreGiftCard $card) => $card->id, $cards))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return array_map(function (StoreGiftCard $card, int $index) use ($locked, $currency) {
            $fresh = $locked->get($card->id);
            if ($fresh === null || ! $this->usable($fresh, $currency)) {
                throw ValidationException::withMessages(['gift_cards.'.$index => self::INVALID]);
            }

            return $fresh;
        }, $cards, array_keys($cards));
    }

    /**
     * Apply cards in order up to the amount due.
     *
     * @param  list<StoreGiftCard>  $cards
     * @return array{cards: list<array{card: StoreGiftCard, applied: string, balance_after: string}>, total: string, amount_due: string}
     */
    public function allocate(array $cards, string $grandTotal): array
    {
        $due = bccomp($grandTotal, '0', 2) > 0 ? bcadd($grandTotal, '0', 2) : '0.00';
        $total = '0.00';
        $rows = [];
        foreach ($cards as $card) {
            $balance = bcadd((string) $card->balance, '0', 2);
            $applied = bccomp($balance, $due, 2) > 0 ? $due : $balance;
            $due = bcsub($due, $applied, 2);
            $total = bcadd($total, $applied, 2);
            $rows[] = ['card' => $card, 'applied' => $applied, 'balance_after' => bcsub($balance, $applied, 2)];
        }

        return ['cards' => $rows, 'total' => $total, 'amount_due' => $due];
    }

    /**
     * The quote: masked cards, what each pays and what is left to pay.
     *
     * @param  array<int, mixed>  $codes
     * @return array{gift_cards: list<array{last4:string,applied:string,balance_after:string}>, gift_card_total: string, amount_due: string}
     */
    public function quote(Store $store, array $codes, string $grandTotal, string $currency): array
    {
        $allocation = $this->allocate($this->resolve($store, $codes, $currency), $grandTotal);

        return ['gift_cards' => self::present($allocation), 'gift_card_total' => $allocation['total'], 'amount_due' => $allocation['amount_due']];
    }

    /** @return list<array{last4:string,applied:string,balance_after:string}> */
    public static function present(array $allocation): array
    {
        return array_map(fn (array $row) => ['last4' => $row['card']->last4, 'applied' => $row['applied'], 'balance_after' => $row['balance_after']], $allocation['cards']);
    }

    /** @return list<array{gift_card_id:int,last4:string,amount:string}> */
    public static function details(array $allocation): array
    {
        return array_values(array_map(fn (array $row) => ['gift_card_id' => (int) $row['card']->id, 'last4' => $row['card']->last4, 'amount' => $row['applied']],
            array_filter($allocation['cards'], fn (array $row) => bccomp($row['applied'], '0', 2) > 0)));
    }

    /** Called inside placement with the cards locked by {@see resolve()}. */
    public function redeem(StoreOrder $order, array $allocation): void
    {
        foreach ($allocation['cards'] as $row) {
            if (bccomp($row['applied'], '0', 2) <= 0) {
                continue;
            }
            /** @var StoreGiftCard $card */
            $card = $row['card'];
            $card->forceFill(['balance' => $row['balance_after']])->save();
            $this->log($card, $order->id, bcsub('0', $row['applied'], 2), 'redeemed', null, null);
        }
    }

    /** Put redeemed amounts back once per card and order (cancellation before fulfilment, refund). */
    public function restore(StoreOrder $order, ?int $actorId = null): void
    {
        DB::transaction(function () use ($order, $actorId) {
            $redemptions = StoreGiftCardTransaction::forStore($order->store_id)->where('store_order_id', $order->id)
                ->where('reason', 'redeemed')->orderBy('gift_card_id')->get();
            if ($redemptions->isEmpty()) {
                return;
            }
            $cards = StoreGiftCard::forStore($order->store_id)->whereIn('id', $redemptions->pluck('gift_card_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($redemptions as $redemption) {
                $card = $cards->get($redemption->gift_card_id);
                $done = StoreGiftCardTransaction::forStore($order->store_id)->where('gift_card_id', $redemption->gift_card_id)
                    ->where('store_order_id', $order->id)->where('reason', 'restored')->exists();
                if ($card === null || $done) {
                    continue;
                }
                $amount = bcsub('0', (string) $redemption->amount, 2);
                $card->forceFill(['balance' => bcadd((string) $card->balance, $amount, 2)])->save();
                $this->log($card, $order->id, $amount, 'restored', $actorId, 'Order '.$order->order_number.' '.$order->status);
            }
        });
    }

    /** A merchant correction with a reason; the balance never goes below zero. */
    public function adjust(StoreGiftCard $card, string $amount, string $note, int $actorId): StoreGiftCard
    {
        return DB::transaction(function () use ($card, $amount, $note, $actorId) {
            $locked = StoreGiftCard::forStore($card->store_id)->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $amount = bcadd($amount, '0', 2);
            $balance = bcadd((string) $locked->balance, $amount, 2);
            if (bccomp($amount, '0', 2) === 0 || bccomp($balance, '0', 2) < 0 || bccomp($balance, ManualStoreOrders::MAX_MONEY, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a non-zero amount that keeps the balance between zero and the supported maximum.']);
            }
            $locked->forceFill(['balance' => $balance])->save();
            $this->log($locked, null, $amount, 'adjusted', $actorId, $note);

            return $locked;
        });
    }

    private function log(StoreGiftCard $card, ?int $orderId, string $amount, string $reason, ?int $actorId, ?string $note): void
    {
        $row = new StoreGiftCardTransaction([
            'store_id' => $card->store_id, 'gift_card_id' => $card->id, 'store_order_id' => $orderId, 'amount' => $amount,
            'balance_after' => (string) $card->balance, 'reason' => $reason, 'note' => $note, 'actor_id' => $actorId,
        ]);
        $row->save();
    }
}
