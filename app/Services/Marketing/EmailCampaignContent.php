<?php

namespace App\Services\Marketing;

use App\Mail\StoreNotificationMail;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Services\Notifications\StoreNotificationSettings;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The block content of an e-mail campaign: write-time validation and sanitizing, the render model the
 * mail view consumes, and the set of URLs the content links to (the only click-redirect targets).
 *
 * Blocks: heading, text (sanitized HTML), image, button, products (store product ids), divider and
 * coupon (a store discount code, read at render time).
 */
class EmailCampaignContent
{
    public const TYPES = ['heading', 'text', 'image', 'button', 'products', 'divider', 'coupon'];

    public const MAX_BLOCKS = 40;

    public const MAX_PRODUCTS = 12;

    public function __construct(private readonly StorefrontUrlGenerator $urls) {}

    /**
     * Validate and normalize merchant blocks. Unknown keys are dropped and text HTML is sanitized.
     *
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public function normalize(Store $store, array $blocks): array
    {
        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['content' => __('A campaign can have at most :max blocks.', ['max' => self::MAX_BLOCKS])]);
        }
        $clean = [];
        foreach (array_values($blocks) as $i => $block) {
            $error = fn (string $field, string $message) => fn () => ValidationException::withMessages(["content.$i.$field" => $message]);
            if (! is_array($block) || ! in_array($block['type'] ?? null, self::TYPES, true)) {
                throw $error('type', __('Choose a valid block type.'))();
            }
            $id = is_string($block['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $block['id']) ? $block['id'] : 'b'.($i + 1);
            $text = fn (string $field, int $max, bool $required = false) => $this->text($block[$field] ?? null, $max, $required, $error($field, $required
                ? __('This field is required (max :max characters).', ['max' => $max])
                : __('Must be :max characters or fewer.', ['max' => $max])));
            $align = in_array($block['align'] ?? null, ['start', 'center', 'end'], true) ? $block['align'] : 'start';
            $clean[] = ['id' => $id] + match ($block['type']) {
                'heading' => ['type' => 'heading', 'text' => $text('text', 200, true), 'size' => ($block['size'] ?? null) === 'medium' ? 'medium' : 'large', 'align' => $align],
                'text' => ['type' => 'text', 'html' => $this->html($block['html'] ?? null, $error('html', __('Add some text (max 20,000 characters).'))), 'align' => $align],
                'image' => ['type' => 'image', 'src' => $this->url($block['src'] ?? null, true, $error('src', __('Enter an image URL starting with https://.'))),
                    'alt' => $text('alt', 200), 'url' => $this->url($block['url'] ?? null, false, $error('url', __('Enter a link starting with http:// or https://.')))],
                'button' => ['type' => 'button', 'label' => $text('label', 80, true),
                    'url' => $this->url($block['url'] ?? null, true, $error('url', __('Enter a link starting with http:// or https://.'))), 'align' => $align],
                'products' => ['type' => 'products', 'product_ids' => $this->products($store, $block['product_ids'] ?? null, $error('product_ids', __('Choose 1 to :max products of this store.', ['max' => self::MAX_PRODUCTS])))],
                'divider' => ['type' => 'divider'],
                'coupon' => ['type' => 'coupon', 'coupon_id' => $this->coupon($store, $block['coupon_id'] ?? null, $error('coupon_id', __('Choose an active discount code of this store.'))),
                    'text' => $text('text', 300)],
            };
        }

        return $clean;
    }

    /**
     * Everything the e-mail view needs. With a recipient, links go through click tracking and the
     * footer carries that recipient's unsubscribe link; without one (preview / test) links are direct.
     *
     * @return array<string, mixed>
     */
    public function render(StoreEmailCampaign $campaign, Store $store, ?StoreEmailCampaignRecipient $recipient = null): array
    {
        $ar = $campaign->locale === 'ar';
        $link = $recipient
            ? fn (?string $url) => $url && preg_match('#^https?://#i', $url) ? self::clickUrl($recipient->token, $url) : $url
            : fn (?string $url) => $url;
        $home = $this->homeUrl($store);
        $blocks = $this->resolve($store, $campaign->content ?? []);
        foreach ($blocks as &$block) {
            if (isset($block['url'])) {
                $block['href'] = $link($block['url']);
            }
            if ($block['type'] === 'text') {
                $block['html'] = self::rewriteLinks($block['html'], $link);
            }
            if ($block['type'] === 'products') {
                foreach ($block['items'] as &$item) {
                    $item['href'] = $link($item['url']);
                }
                unset($item);
            }
        }
        unset($block);

        return [
            'ar' => $ar,
            'store' => $store,
            'logo' => $store->logoUrl(),
            'accent' => StoreNotificationMail::accent($store),
            'preheader' => (string) $campaign->preheader,
            'subject' => (string) $campaign->subject,
            'blocks' => $blocks,
            'homeUrl' => $link($home),
            'unsubscribeUrl' => route('email-campaigns.unsubscribe', ['token' => $recipient?->token ?? 'test']),
            'openPixel' => $recipient ? route('email-campaigns.open', ['token' => $recipient->token]) : null,
        ];
    }

    /**
     * Every http(s) URL the campaign links to (store home, buttons, image links, text links, products).
     *
     * @return list<string>
     */
    public function links(StoreEmailCampaign $campaign, Store $store): array
    {
        $urls = [$this->homeUrl($store)];
        foreach ($this->resolve($store, $campaign->content ?? []) as $block) {
            $urls[] = $block['url'] ?? null;
            foreach ($block['items'] ?? [] as $item) {
                $urls[] = $item['url'];
            }
            if ($block['type'] === 'text') {
                self::rewriteLinks($block['html'], function (?string $url) use (&$urls) {
                    $urls[] = $url;

                    return $url;
                });
            }
        }

        return array_values(array_unique(array_filter($urls, fn ($url) => is_string($url) && preg_match('#^https?://#i', $url))));
    }

    public static function clickUrl(string $token, string $url): string
    {
        return route('email-campaigns.click', ['token' => $token, 'u' => $url, 's' => self::signature($token, $url)]);
    }

    public static function signature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token."\n".$url, (string) config('app.key')), 0, 32);
    }

    public function homeUrl(Store $store): string
    {
        return $this->urls->publicUrl($store, '/') ?? rtrim((string) config('sellchase.marketing_url'), '/').'/';
    }

    /**
     * Blocks with their database-backed parts (products, coupon) loaded; blocks whose data is gone
     * (deleted product, disabled code) are left out rather than rendered empty.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function resolve(Store $store, array $blocks): array
    {
        $productIds = collect($blocks)->where('type', 'products')->pluck('product_ids')->flatten()->unique()->values();
        $products = $productIds->isEmpty() ? collect() : Product::forStore($store)->whereIn('id', $productIds)->where('is_active', true)
            ->get(['id', 'name', 'slug', 'price', 'compare_price', 'image', 'currency'])->keyBy('id');
        $couponIds = collect($blocks)->where('type', 'coupon')->pluck('coupon_id')->filter()->unique()->values();
        $coupons = $couponIds->isEmpty() ? collect() : Coupon::forStore($store)->whereIn('id', $couponIds)->get()->keyBy('id');
        $currency = $store->currency ?: 'USD';
        $out = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'products') {
                $items = collect($block['product_ids'] ?? [])->map(fn ($id) => $products->get((int) $id))->filter()
                    ->map(fn (Product $product) => [
                        'name' => (string) $product->name,
                        'price' => StoreNotificationSettings::money($product->price, $product->currency ?: $currency),
                        'compare' => (float) $product->compare_price > (float) $product->price ? StoreNotificationSettings::money($product->compare_price, $product->currency ?: $currency) : null,
                        'image' => $product->imageUrl(),
                        'url' => (string) ($this->urls->publicUrl($store, '/products/'.rawurlencode((string) $product->slug)) ?? $this->homeUrl($store)),
                    ])->values()->all();
                if ($items === []) {
                    continue;
                }
                $block['items'] = $items;
            }
            if (($block['type'] ?? null) === 'coupon') {
                $coupon = $coupons->get((int) ($block['coupon_id'] ?? 0));
                if (! $coupon || ! self::couponUsable($coupon)) {
                    continue;
                }
                $block['code'] = (string) $coupon->code;
                $block['title'] = (string) ($coupon->title ?? '');
                $block['value'] = self::couponValue($coupon, $currency);
                $block['expires_at'] = $coupon->expires_at?->toDateString();
            }
            $out[] = $block;
        }

        return $out;
    }

    public static function couponUsable(Coupon $coupon): bool
    {
        return $coupon->method === 'code' && $coupon->is_active && filled($coupon->code)
            && (! $coupon->expires_at || $coupon->expires_at->isFuture());
    }

    /** @return array{type: string, amount: string|null} */
    public static function couponValue(Coupon $coupon, string $currency): array
    {
        return match ($coupon->type) {
            'percentage' => ['type' => 'percentage', 'amount' => rtrim(rtrim(number_format((float) $coupon->value, 2, '.', ''), '0'), '.').'%'],
            'fixed' => ['type' => 'fixed', 'amount' => StoreNotificationSettings::money($coupon->value, $currency)],
            default => ['type' => (string) $coupon->type, 'amount' => null],
        };
    }

    /** Rewrite (or just collect) the href of every anchor in sanitized HTML. */
    public static function rewriteLinks(string $html, callable $link): string
    {
        return (string) preg_replace_callback('/<a\s([^>]*?)href="([^"]*)"/i', function (array $match) use ($link) {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

            return '<a '.$match[1].'href="'.e((string) $link($url)).'"';
        }, $html);
    }

    public static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->withMaxInputLength(20000);
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'span', 'small'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title'])->forceAttribute('a', 'target', '_blank')->forceAttribute('a', 'rel', 'noopener noreferrer');

        return new HtmlSanitizer($config);
    }

    private function text(mixed $value, int $max, bool $required, \Closure $error): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw $error();
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $value));
        if (($required && $value === '') || mb_strlen($value) > $max) {
            throw $error();
        }

        return $value === '' ? null : $value;
    }

    private function html(mixed $value, \Closure $error): string
    {
        if (! is_string($value) || mb_strlen($value) > 20000) {
            throw $error();
        }
        $clean = trim(self::sanitizer()->sanitize($value));
        if (trim(strip_tags($clean)) === '') {
            throw $error();
        }

        return $clean;
    }

    private function url(mixed $value, bool $required, \Closure $error): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            if ($required) {
                throw $error();
            }

            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 2048 || ! preg_match('#^https?://[^\s<>"]+$#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw $error();
        }

        return $value;
    }

    /** @return list<int> */
    private function products(Store $store, mixed $ids, \Closure $error): array
    {
        if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_PRODUCTS) {
            throw $error();
        }
        $ids = array_values(array_unique(array_map(fn ($id) => is_numeric($id) ? (int) $id : 0, $ids)));
        /** @var Collection<int, int> $found */
        $found = Product::forStore($store)->whereIn('id', $ids)->pluck('id');
        if (in_array(0, $ids, true) || $found->count() !== count($ids)) {
            throw $error();
        }

        return $ids;
    }

    private function coupon(Store $store, mixed $id, \Closure $error): int
    {
        $coupon = is_numeric($id) ? Coupon::forStore($store)->find((int) $id) : null;
        if (! $coupon || $coupon->method !== 'code' || ! filled($coupon->code)) {
            throw $error();
        }

        return $coupon->id;
    }
}
