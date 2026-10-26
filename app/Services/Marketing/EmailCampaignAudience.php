<?php

namespace App\Services\Marketing;

use App\Models\Store;
use App\Models\StoreCustomerProfile;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Services\Commerce\StoreCustomerDirectory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Who a campaign goes to. Consent is the store's e-mail newsletter list: whatever the audience type,
 * only addresses with an ACTIVE e-mail subscription are ever selected — a customer who ordered but
 * never opted in is not reachable. Addresses that unsubscribed from or bounced on any earlier
 * campaign of the store are excluded, and every address appears once.
 *
 * Types: "all" (every active e-mail subscriber), "segment" (customer-directory filters, e.g. orders or
 * amount spent ranges, dates, account, tags) and "tags" (customers carrying any of the tags).
 */
class EmailCampaignAudience
{
    public const TYPES = ['all', 'segment', 'tags'];

    public function __construct(private readonly StoreCustomerDirectory $directory) {}

    /**
     * Validate an audience definition into its stored shape.
     *
     * @return array{type: string, filters?: array<string, mixed>, tags?: list<string>}
     */
    public function normalize(mixed $audience): array
    {
        $data = Validator::make(['audience' => $audience], [
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'in:'.implode(',', self::TYPES)],
            'audience.tags' => ['exclude_unless:audience.type,tags', 'required', 'array', 'min:1', 'max:20'],
            'audience.tags.*' => ['string', 'max:'.StoreCustomerProfile::MAX_TAG_LENGTH],
            'audience.filters' => ['exclude_unless:audience.type,segment', 'required', 'array'],
            'audience.filters.orders_min' => ['nullable', 'integer', 'min:0'], 'audience.filters.orders_max' => ['nullable', 'integer', 'min:0'],
            'audience.filters.spent_min' => ['nullable', 'numeric', 'min:0'], 'audience.filters.spent_max' => ['nullable', 'numeric', 'min:0'],
            'audience.filters.last_order_from' => ['nullable', 'date'], 'audience.filters.last_order_to' => ['nullable', 'date'],
            'audience.filters.first_order_from' => ['nullable', 'date'],
            'audience.filters.account' => ['nullable', 'in:registered,guest'],
            'audience.filters.tag' => ['nullable', 'array', 'max:20'], 'audience.filters.tag.*' => ['string', 'max:'.StoreCustomerProfile::MAX_TAG_LENGTH],
        ], [], ['audience.type' => 'audience'])->validate()['audience'];

        return match ($data['type']) {
            'all' => ['type' => 'all'],
            'tags' => ['type' => 'tags', 'tags' => array_values(array_unique(array_filter(array_map('trim', $data['tags']), fn ($tag) => $tag !== '')))],
            default => ['type' => 'segment', 'filters' => array_filter(
                array_intersect_key($data['filters'], array_flip(['orders_min', 'orders_max', 'spent_min', 'spent_max', 'last_order_from', 'last_order_to', 'first_order_from', 'account', 'tag'])),
                fn ($value) => $value !== null && $value !== '' && $value !== [],
            )],
        };
    }

    /**
     * Eligible addresses as rows of (email, customer_key), deduplicated and with suppressions removed.
     *
     * @param  array<string, mixed>  $audience
     */
    public function query(Store $store, array $audience): QueryBuilder
    {
        $storeId = $store->id;
        $type = $audience['type'] ?? 'all';
        if ($type === 'all') {
            $source = DB::table('newsletter_subscribers')->where('store_id', $storeId)->where('is_active', true)
                ->selectRaw('LOWER(email) as email, NULL as customer_key');
        } else {
            $filters = $type === 'tags' ? ['tag' => $audience['tags'] ?? []] : (array) ($audience['filters'] ?? []);
            unset($filters['marketing'], $filters['search'], $filters['sort_by'], $filters['sort_direction']);
            // The directory joins each customer to their ACTIVE e-mail subscription (ns); requiring it
            // limits the segment to consenting addresses.
            $source = $this->directory->query($store, $filters)->reorder()->whereNotNull('ns.id')
                ->select(['ns.email as email', 'c.customer_key as customer_key']);
        }

        return DB::query()->fromSub($source, 'a')
            ->where('a.email', 'like', '%_@_%')
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('store_email_campaign_recipients as s')
                ->where('s.store_id', $storeId)->whereColumn('s.email', 'a.email')->whereIn('s.status', StoreEmailCampaignRecipient::SUPPRESSED))
            ->groupBy('a.email')
            ->select('a.email')->selectRaw('MAX(a.customer_key) as customer_key');
    }

    /** @param  array<string, mixed>  $audience */
    public function count(Store $store, array $audience): int
    {
        return DB::query()->fromSub($this->query($store, $audience), 'eligible')->count();
    }

    /**
     * Insert the campaign's recipients (idempotent: a second run only adds missing addresses).
     */
    public function snapshot(StoreEmailCampaign $campaign, Store $store): int
    {
        $this->query($store, $campaign->audience ?? ['type' => 'all'])->orderBy('a.email')
            ->chunk(1000, function ($rows) use ($campaign): void {
                $now = now();
                $insert = [];
                foreach ($rows as $row) {
                    $email = mb_strtolower(trim((string) $row->email));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        continue;
                    }
                    $insert[] = ['store_id' => $campaign->store_id, 'campaign_id' => $campaign->id, 'email' => $email,
                        'customer_key' => $row->customer_key !== null ? mb_substr((string) $row->customer_key, 0, 255) : null,
                        'status' => 'queued', 'token' => Str::random(48), 'created_at' => $now, 'updated_at' => $now];
                }
                if ($insert !== []) {
                    StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->insertOrIgnore($insert);
                }
            });

        return StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('campaign_id', $campaign->id)->count();
    }
}
