<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontBrandResource;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Http\Resources\Storefront\StorefrontCollectionResource;
use App\Http\Resources\Storefront\StorefrontCouponResource;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Models\Store;
use App\Models\StoreContentPage;
use App\Models\StoreMenu;
use App\Models\StorePaymentGateway;
use App\Services\Commerce\Apps\ProductBundles;
use App\Services\Commerce\Apps\SocialProof;
use App\Services\Commerce\DownsellOffers;
use App\Services\Commerce\GoogleMerchantSettings;
use App\Services\Commerce\PaymentGatewayCatalog;
use App\Services\Commerce\ShoppingPreferences;
use App\Services\Commerce\StoreHeadCode;
use App\Services\Commerce\StoreShipping;
use App\Services\Commerce\StoreTrackingSettings;
use App\Services\CurrencyRateService;
use App\Services\PageBuilder\StoreMenuService;
use App\Services\Storefront\SimplePages;
use App\Services\Storefront\SpaShell;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use App\Services\Storefront\VisitorCurrency;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeResolver;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
        private readonly StorefrontContextBuilder $builder,
        private readonly ThemeResolver $themes,
        private readonly CurrencyRateService $currencyRates,
        private readonly LocaleContext $locale,
        private readonly StoreMenuService $menus,
    ) {}

    /**
     * GET /storefront — homepage payload + SEO for the resolved store, plus the
     * resolved `locale` block and the header/footer `navigation` trees (labels
     * already picked for that locale). Theme settings arrive as flat scalars.
     */
    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $preview = $this->themes->resolvePreview($store, $request->query('__preview'), $this->locale->current());
        $theme = $preview ?? $this->themes->resolve($store, $this->locale->current());
        $previewRequested = $request->has('__preview');
        $pageToken = $request->query('__preview');
        $validPreview = $preview !== null || (is_string($pageToken) && app(ThemePreviewToken::class)->verifyPage($pageToken, $store->id) !== null);

        return response()->json([
            'store' => $this->storeSummary($store, $request),
            ...($previewRequested ? ['preview' => ['valid' => $validPreview]] : []),
            'head_code' => app(StoreHeadCode::class)->publication($store, $request),
            'locale' => $this->locale->toArray(),
            'seo' => $this->seo->forStore($store),
            'theme' => $theme === null ? null : [
                'key' => $theme['key'],
                'version' => $theme['version'],
                'bundle_url' => $theme['bundle_url'] ?? null,
                'bundle_integrity' => $theme['bundle_integrity'] ?? null,
                'settings' => $theme['settings'],
                'settings_i18n' => $theme['settings_i18n'] ?? [],
                'custom_css' => $theme['custom_css'] ?? null,
            ],
            'homepage' => $this->storefront->homepage($store),
            'navigation' => $this->navigation($store),
        ], 200, $previewRequested ? ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'] : ($store->auto_currency_by_country ? ['Cache-Control' => 'private, no-store'] : []), JSON_UNESCAPED_UNICODE);
    }

    /** Header/footer menu trees for the current locale; empty arrays when the store has none. */
    private function navigation(Store $store): array
    {
        $menus = StoreMenu::query()
            ->where('store_id', $store->id)
            ->whereIn('handle', ['header', 'footer'])
            ->get()->keyBy('handle');
        $locale = $this->locale->current();

        $navigation = app(SimplePages::class)->navigation($store, [
            'header' => $menus->has('header') ? $this->menus->tree($menus->get('header'), $locale) : [],
            'footer' => $menus->has('footer') ? $this->menus->tree($menus->get('footer'), $locale) : [],
        ], $locale);

        return app(GoogleMerchantSettings::class)->navigation($store, $navigation);
    }

    /**
     * GET /storefront/context — the full, presentation-agnostic render context
     * { store, seo, theme, page, data } that the React SSR runtime consumes.
     */
    public function context(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $template = in_array($request->query('template'), ['home', 'product', 'category'], true)
            ? (string) $request->query('template')
            : 'home';
        $params = $request->filled('slug') ? ['slug' => (string) $request->query('slug')] : [];

        $preview = $this->themes->resolvePreview($store, $request->query('__preview'), $this->locale->current());
        $context = $this->builder->build($store, $template, $params, $preview);
        abort_if($context === null, 404, 'Not found.');

        return response()->json($context, 200, ($preview !== null || $store->auto_currency_by_country) ? ['Cache-Control' => 'private, no-store'] : [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /storefront/home — one consolidated payload for the themed home page: catalogue rows
     * (newest + flag-driven best-sellers/new-arrivals/on-sale/trending), categories, collections,
     * brands, active coupons, and the editable home/about/faq content. Collapses what used to be a
     * dozen separate requests into one, so the home page never trips the read rate limiter.
     */
    public function home(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $row = fn (string $filter) => StorefrontProductResource::collection(
            $this->storefront->products(null, 8, $filter)->getCollection()
        );

        return response()->json([
            'data' => [
                'products' => StorefrontProductResource::collection($this->storefront->products(null, 12)->getCollection()),
                'best_sellers' => $row('best_sellers'),
                'new_arrivals' => $row('new_arrivals'),
                'on_sale' => $row('on_sale'),
                'trending' => $row('trending'),
                'categories' => StorefrontCategoryResource::collection($this->storefront->categories()),
                'collections' => StorefrontCollectionResource::collection($this->storefront->collections(12)),
                'brands' => StorefrontBrandResource::collection($this->storefront->brands()),
                'coupons' => StorefrontCouponResource::collection($this->storefront->coupons()),
                'content' => $this->contentBundle(['home', 'about', 'faq']),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Published content JSON for the given system-page keys, as `{ key: {en,ar}|null }`.
     * StoreScope isolates to the resolved store.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function contentBundle(array $keys): array
    {
        $rows = StoreContentPage::query()
            ->whereIn('key', $keys)
            ->where('is_published', true)
            ->get()
            ->keyBy('key');

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $rows->has($key) ? $rows->get($key)->data : null;
        }

        return $out;
    }

    private function storeSummary(Store $store, Request $request): array
    {
        $baseCurrency = strtoupper((string) ($store->currency ?: 'USD'));
        $supported = collect($store->supported_currencies ?? [$baseCurrency])
            ->map(fn ($code) => strtoupper((string) $code))->push($baseCurrency)->unique()->values();
        $multipliers = $supported->mapWithKeys(function (string $currency) use ($baseCurrency): array {
            $rate = $this->currencyRates->conversionMultiplier($baseCurrency, $currency);

            return $rate === null ? [] : [$currency => $rate];
        })->all();

        $visitorCurrency = app(VisitorCurrency::class);
        if ($store->auto_currency_by_country) {
            $rates = $visitorCurrency->rates();
            foreach ($multipliers as $code => $unused) {
                if (is_numeric($rates[$code] ?? null) && is_numeric($rates[$baseCurrency] ?? null) && $rates[$baseCurrency] > 0) {
                    $multipliers[$code] = round($rates[$code] / $rates[$baseCurrency], 8);
                }
            }
        }

        $payments = StorePaymentGateway::where('store_id', $store->id)->where('enabled', true)->orderBy('sort_order')->get()->filter(fn (StorePaymentGateway $setting) => PaymentGatewayCatalog::offered($setting, $baseCurrency));
        $paymentMarks = $payments->map(function (StorePaymentGateway $setting) {
            $presentation = PaymentGatewayCatalog::presentation($setting);
            $path = $presentation['logo_url'];

            return ['name' => $presentation['name'], 'logo_url' => is_string($path) && str_starts_with($path, '/') ? app(SpaShell::class)->assetOrigin().$path : $path];
        })->values()->all();

        return [
            'auto_currency_by_country' => (bool) $store->auto_currency_by_country,
            'visitor_currency' => $store->auto_currency_by_country
                ? $visitorCurrency->choose($visitorCurrency->country($request->ip()), array_keys($multipliers), $baseCurrency) : $baseCurrency,
            'id' => $store->id,
            'name' => $store->name,
            'description' => $store->description,
            'email' => $store->email,
            'phone' => $store->phone,
            'identity' => $store->identity(),
            'payment_methods' => $payments->pluck('gateway')->values()->all(),
            'payment_marks' => $paymentMarks,
            'slug' => $store->slug,
            'currency' => $baseCurrency,
            'auto_select_variants' => app(ShoppingPreferences::class)->configured($store)['auto_select_variants'],
            'tracking_enabled' => app(StoreTrackingSettings::class)->enabled($store),
            'downsell_enabled' => app(DownsellOffers::class)->enabled($store),
            'social_proof_enabled' => app(SocialProof::class)->enabled($store),
            'bundles_enabled' => app(ProductBundles::class)->enabled($store),
            'has_collections' => $this->storefront->hasCollections($store),
            'merchant_information' => app(GoogleMerchantSettings::class)->publicInformation($store),
            'supported_currencies' => array_keys($multipliers),
            'currency_multipliers' => $multipliers,
            'shipping' => ['enabled' => (bool) $store->shipping_enabled, 'free_over' => app(StoreShipping::class)->zonesEnabled($store) ? null : $store->shipping_free_over, 'currency' => $baseCurrency],
            'default_locale' => $store->default_locale,
            'supported_locales' => $store->supported_locales ?? [$store->default_locale ?: 'en'],
            'timezone' => $store->timezone,
            'status' => $store->status,
            'logo_url' => $store->logoUrl(),
            'banner_url' => $store->bannerUrl(),
        ];
    }
}
