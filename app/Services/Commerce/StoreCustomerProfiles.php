<?php

namespace App\Services\Commerce;

use App\Models\NewsletterSubscriber;
use App\Models\PhoneNewsletterSubscriber;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerProfile;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One customer of the order-derived directory (see {@see StoreCustomerDirectory::key()}): aggregate
 * stats, addresses and orders, plus the merchant's profile (tags, note, contact overrides).
 *
 * Marketing consent is not a second flag on the profile: it is the store's newsletter subscriber
 * lists (e-mail and phone), which the storefront opt-in already writes and the Newsletter page
 * already lists. Toggling consent here subscribes/unsubscribes the customer's phone (their key)
 * and e-mail on those lists; the profile only records when and by whom it was last changed.
 */
class StoreCustomerProfiles
{
    public function __construct(private readonly OrderLimits $limits) {}

    /** "order" (no contact details), "email" or "phone", or null when the key cannot be a directory key. */
    public static function keyType(string $key): ?string
    {
        return match (true) {
            (bool) preg_match('/^order:[1-9]\d{0,18}$/', $key) => 'order',
            (bool) preg_match('/^[^\s@]+@[^\s@]+$/u', $key) => 'email',
            (bool) preg_match('/^\+?\d{4,20}$/', $key) => 'phone',
            default => null,
        };
    }

    /** Keys are case-insensitive for e-mail, exact otherwise. */
    public static function normalizeKey(string $key): string
    {
        $key = trim($key);

        return self::keyType($key) === 'email' ? mb_strtolower($key) : $key;
    }

    /**
     * Every (non-deleted) order of this customer, cancelled ones included. Mirrors the directory key
     * expression with index-friendly conditions per key type.
     *
     * @return Builder<StoreOrder>
     */
    public function orders(Store $store, string $key): Builder
    {
        $query = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_orders.store_id', $store->id);
        $noPhone = "COALESCE(customer_phone_normalized, '') = ''";

        return match (self::keyType($key)) {
            'phone' => $query->where('customer_phone_normalized', $key),
            'email' => $query->whereRaw($noPhone)->whereRaw('LOWER(customer_email) = ?', [$key]),
            'order' => $query->whereRaw($noPhone)->whereRaw("COALESCE(customer_email, '') = ''")->whereKey((int) substr($key, 6)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** The registered account behind a key: linked on their orders, else matched by e-mail/phone. */
    public function account(Store $store, string $key, ?int $linkedId = null): ?StoreCustomer
    {
        $accounts = StoreCustomer::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id);
        if ($linkedId) {
            return (clone $accounts)->find($linkedId);
        }
        $type = self::keyType($key);
        if ($type === 'email') {
            return $accounts->whereRaw('LOWER(email) = ?', [$key])->orderBy('id')->first();
        }
        if ($type !== 'phone') {
            return null;
        }
        // Account phones are stored as typed; narrow by the last digits, then compare normalized.
        $tail = substr(preg_replace('/\D/', '', $key) ?? '', -7);

        return $accounts->where('phone', 'like', '%'.$tail.'%')->orderBy('id')->limit(50)->get()
            ->first(fn (StoreCustomer $account) => $this->accountKey($store, $account) === $key);
    }

    /** The directory key a registered account's orders would be grouped under. */
    public function accountKey(Store $store, StoreCustomer $account): ?string
    {
        $phone = $this->limits->normalizePhone($account->phone, $this->limits->configured($store)['phone_country']);

        return $phone ?? (filled($account->email) ? mb_strtolower(trim((string) $account->email)) : null);
    }

    public function exists(Store $store, string $key): bool
    {
        return $this->orders($store, $key)->exists() || $this->account($store, $key) !== null;
    }

    /** @return array<string, mixed>|null */
    public function show(Store $store, string $key, int $page = 1, int $perPage = 10): ?array
    {
        $orders = $this->orders($store, $key);
        $report = (new StoreReportingMoney($store))->report((clone $orders)->where('status', '!=', 'cancelled')->toBase());
        $stats = (clone $orders)->where('status', '!=', 'cancelled')->toBase()
            ->selectRaw('COUNT(*) as orders_count, MIN(COALESCE(placed_at, created_at)) as first_order_at, MAX(COALESCE(placed_at, created_at)) as last_order_at')
            ->selectRaw('MAX(customer_name) as name, MAX(customer_email) as email, MAX(customer_phone) as phone, MAX(store_customer_id) as account_id')
            ->first();
        $any = (clone $orders)->toBase()->selectRaw('COUNT(*) as total, MAX(customer_name) as name, MAX(customer_email) as email, MAX(customer_phone) as phone, MAX(store_customer_id) as account_id')->first();
        $account = $this->account($store, $key, ($stats->account_id ?? $any->account_id) ? (int) ($stats->account_id ?? $any->account_id) : null);
        if ((int) $any->total === 0 && $account === null) {
            return null;
        }

        $profile = $this->profile($store, $key);
        $count = (int) $stats->orders_count;
        $spent = $report['base_equivalent'];
        $fromOrders = fn (string $field) => $stats->{$field} ?? $any->{$field} ?? null;
        $contact = [
            'name' => $profile->name ?: ($fromOrders('name') ?? $account?->name),
            'email' => $profile->email ?: ($fromOrders('email') ?? $account?->email),
            'phone' => $profile->phone ?: ($fromOrders('phone') ?? $account?->phone),
        ];

        $addresses = [];
        foreach ((clone $orders)->whereNotNull('shipping_address')->orderByDesc('id')->limit(20)->get(['id', 'shipping_address', 'placed_at', 'created_at']) as $order) {
            $formatted = StoreOrderExports::address($order->shipping_address);
            if ($formatted === '' || isset($addresses[mb_strtolower($formatted)])) {
                continue;
            }
            $fields = array_filter((array) $order->shipping_address, fn ($value, $field) => is_scalar($value) && $field !== 'shipping_details', ARRAY_FILTER_USE_BOTH);
            $addresses[mb_strtolower($formatted)] = ['formatted' => $formatted, 'fields' => $fields, 'order_id' => $order->id, 'used_at' => ($order->placed_at ?? $order->created_at)?->toIso8601String()];
            if (count($addresses) === 3) {
                break;
            }
        }

        $history = (clone $orders)->select(['id', 'order_number', 'status', 'payment_status', 'payment_method', 'currency', 'grand_total', 'placed_at', 'created_at'])
            ->orderByRaw('COALESCE(placed_at, created_at) DESC')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'key' => $key,
            'key_type' => self::keyType($key),
            'contact' => $contact,
            'stats' => [
                'orders_count' => $count,
                'total_spent' => $spent,
                'average_order_value' => StoreReportingMoney::average($spent, $count),
                'first_order_at' => self::iso($stats->first_order_at),
                'last_order_at' => self::iso($stats->last_order_at),
                'cancelled_count' => (int) $any->total - $count,
                'currency' => $store->currency ?: 'USD',
                'reporting_money' => $report,
            ],
            'addresses' => array_values($addresses),
            'account' => $account ? ['id' => $account->id, 'name' => $account->name, 'email' => $account->email, 'phone' => $account->phone,
                'is_active' => (bool) $account->is_active, 'created_at' => $account->created_at?->toIso8601String()] : null,
            'profile' => [
                'tags' => array_values($profile->tags ?? []),
                'note' => $profile->note,
                'name' => $profile->name,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'updated_at' => $profile->exists ? $profile->updated_at?->toIso8601String() : null,
            ],
            'marketing' => $this->marketing($store, $key, $profile, $contact['email']),
            'orders' => [
                'data' => collect($history->items())->map(fn (StoreOrder $order) => [
                    'id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status, 'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method, 'currency' => $order->currency, 'grand_total' => round((float) $order->grand_total, 2),
                    'placed_at' => ($order->placed_at ?? $order->created_at)?->toIso8601String(),
                ])->all(),
                'meta' => ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total(), 'per_page' => $history->perPage()],
            ],
        ];
    }

    /**
     * Upsert the merchant's data for a key. Only the fields present in $data change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Store $store, User $user, string $key, array $data): StoreCustomerProfile
    {
        return DB::transaction(function () use ($store, $user, $key, $data): StoreCustomerProfile {
            // Serializes concurrent first saves of the same key (unique store_id + customer_key).
            Store::query()->lockForUpdate()->findOrFail($store->id);
            if (! $this->exists($store, $key)) {
                throw ValidationException::withMessages(['key' => __('No order or customer account in this store matches this customer.')]);
            }
            $profile = $this->profile($store, $key);
            if (array_key_exists('tags', $data)) {
                $profile->tags = $this->normalizeTags($store, (array) ($data['tags'] ?? []));
            }
            foreach (['note', 'name', 'email', 'phone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = trim((string) ($data[$field] ?? ''));
                    $profile->{$field} = $value === '' ? null : $value;
                }
            }
            if ($profile->phone !== null && $this->limits->normalizePhone($profile->phone, $this->limits->configured($store)['phone_country']) === null) {
                throw ValidationException::withMessages(['phone' => __('Enter a valid phone number, including the country code for a foreign number.')]);
            }
            if (array_key_exists('accepts_marketing', $data) && $data['accepts_marketing'] !== null) {
                $email = $profile->email ?: $this->orders($store, $key)->where('status', '!=', 'cancelled')->max('customer_email')
                    ?: $this->orders($store, $key)->max('customer_email') ?: $this->account($store, $key)?->email;
                $this->syncConsent($store, $key, $email, (bool) $data['accepts_marketing'], $profile->name);
                $profile->forceFill(['marketing_updated_at' => now(), 'marketing_source' => 'merchant', 'marketing_updated_by' => $user->id]);
            }
            $profile->save();

            return $profile;
        });
    }

    /**
     * Distinct tags with how many customers carry each, most used first.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tags(Store $store, ?string $search = null): array
    {
        $counts = [];
        $labels = [];
        $profiles = StoreCustomerProfile::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id)->whereNotNull('tags')->select(['id', 'tags']);
        foreach ($profiles->lazyById(500) as $profile) {
            foreach (array_unique(array_map('mb_strtolower', $profile->tags ?? [])) as $lower) {
                $counts[$lower] = ($counts[$lower] ?? 0) + 1;
            }
            foreach ($profile->tags ?? [] as $tag) {
                $labels[mb_strtolower($tag)] ??= $tag;
            }
        }
        $needle = mb_strtolower(trim((string) $search));
        $rows = [];
        foreach ($counts as $lower => $count) {
            if ($needle === '' || str_contains((string) $lower, $needle)) {
                $rows[] = ['tag' => $labels[$lower], 'count' => $count];
            }
        }
        usort($rows, fn (array $a, array $b) => [$b['count'], mb_strtolower($a['tag'])] <=> [$a['count'], mb_strtolower($b['tag'])]);

        return $rows;
    }

    /**
     * The spelling already used in this store for each tag (tags match case-insensitively).
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public function canonicalTags(Store $store, array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $known = [];
        foreach ($this->tags($store) as $row) {
            $known[mb_strtolower($row['tag'])] = $row['tag'];
        }

        return array_values(array_unique(array_map(fn (string $tag) => $known[mb_strtolower($tag)] ?? $tag, $tags)));
    }

    /**
     * Trim, collapse spaces, split on commas, drop empties and case-insensitive duplicates, reuse the
     * store's existing spelling, and enforce the limits.
     *
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    public function normalizeTags(Store $store, array $raw): array
    {
        $tags = [];
        foreach ($raw as $item) {
            foreach (explode(',', is_scalar($item) ? (string) $item : '') as $piece) {
                $tag = trim((string) preg_replace('/\s+/u', ' ', $piece));
                if ($tag === '') {
                    continue;
                }
                if (mb_strlen($tag) > StoreCustomerProfile::MAX_TAG_LENGTH) {
                    throw ValidationException::withMessages(['tags' => __('Each tag must be :max characters or fewer.', ['max' => StoreCustomerProfile::MAX_TAG_LENGTH])]);
                }
                $tags[mb_strtolower($tag)] ??= $tag;
            }
        }
        if (count($tags) > StoreCustomerProfile::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('A customer can have at most :max tags.', ['max' => StoreCustomerProfile::MAX_TAGS])]);
        }

        return $this->canonicalTags($store, array_values($tags));
    }

    private function profile(Store $store, string $key): StoreCustomerProfile
    {
        return StoreCustomerProfile::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id)->where('customer_key', $key)->first()
            ?? new StoreCustomerProfile(['store_id' => $store->id, 'customer_key' => $key]);
    }

    /** @return array<string, mixed> */
    private function marketing(Store $store, string $key, StoreCustomerProfile $profile, ?string $email): array
    {
        $phone = self::keyType($key) === 'phone'
            ? PhoneNewsletterSubscriber::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id)->where('phone_normalized', $key)->first()
            : null;
        $address = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $mail = $address ? NewsletterSubscriber::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id)->where('email', $address)->first() : null;
        $sms = (bool) $phone?->is_active;
        $byEmail = (bool) $mail?->is_active;

        return [
            'accepts_marketing' => $sms || $byEmail,
            'email' => ['address' => $address, 'subscribed' => $byEmail, 'since' => $byEmail ? $mail?->created_at?->toIso8601String() : null],
            'sms' => ['phone' => self::keyType($key) === 'phone' ? $key : null, 'subscribed' => $sms, 'since' => $sms ? $phone?->created_at?->toIso8601String() : null],
            'can_subscribe' => self::keyType($key) === 'phone' || $address !== null,
            'updated_at' => $profile->marketing_updated_at?->toIso8601String(),
            'source' => $profile->marketing_source ?? (($sms || $byEmail) ? 'storefront' : null),
        ];
    }

    private function syncConsent(Store $store, string $key, ?string $email, bool $subscribe, ?string $name): void
    {
        $address = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $phone = self::keyType($key) === 'phone' ? $key : null;
        if ($subscribe && $address === null && $phone === null) {
            throw ValidationException::withMessages(['accepts_marketing' => __('Add an e-mail address or phone number before subscribing this customer to marketing.')]);
        }
        if ($address !== null) {
            $mail = NewsletterSubscriber::query()->withoutGlobalScope(StoreScope::class)->firstOrNew(['store_id' => $store->id, 'email' => $address]);
            if ($subscribe || $mail->exists) {
                $mail->forceFill(['is_active' => $subscribe])->save();
            }
        }
        if ($phone !== null) {
            $sms = PhoneNewsletterSubscriber::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $store->id)->where('phone_normalized', $phone)->first();
            if ($sms) {
                $sms->forceFill(['is_active' => $subscribe])->save();
            } elseif ($subscribe) {
                $raw = $this->orders($store, $key)->whereNotNull('customer_phone')->orderByDesc('id')->value('customer_phone');
                $label = $name ?: $this->orders($store, $key)->whereNotNull('customer_name')->orderByDesc('id')->value('customer_name');
                PhoneNewsletterSubscriber::query()->withoutGlobalScope(StoreScope::class)->create([
                    'store_id' => $store->id, 'phone_normalized' => $phone, 'phone' => mb_substr((string) ($raw ?: $phone), 0, 40),
                    'name' => mb_substr((string) ($label ?: $phone), 0, 255), 'is_active' => true,
                ]);
            }
        }
    }

    private static function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
