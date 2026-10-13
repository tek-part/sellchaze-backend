<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Commerce\CheckoutBotProtection;
use App\Services\Commerce\MerchantGeneralSettings;
use App\Services\Commerce\OrderLimits;
use App\Services\Commerce\ShoppingPreferences;
use App\Services\Commerce\StoreShipping;
use App\Services\Stores\StoreFontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class StoreGeneralSettingsController extends Controller
{
    public function __construct(private readonly MerchantGeneralSettings $settings) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit') || $user->can('store.settings.manage')), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return response()->json(['data' => $this->settings->data($store), 'store' => new StoreResource($store), 'choices' => $this->settings->choices($store)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper($request->input('currency'))]);
        }
        $rules = ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'site_title' => ['present', 'nullable', 'string', 'max:255'], 'header_text' => ['present', 'nullable', 'string', 'max:500'],
            'primary_color' => ['present', 'nullable', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'font_family' => ['present', 'nullable', 'string', Rule::in(StoreFontCatalog::families())],
            'default_locale' => ['required', Rule::in(['ar', 'en'])], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', ...($request->input('currency') === ($store->currency ?: 'USD') ? [] : [Rule::exists('currency_rates', 'currency_code')])],
            'timezone' => ['required', 'timezone'], 'shipping_flat_rate' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'phone_country' => ['required', Rule::in(PhoneNumberUtil::getInstance()->getSupportedRegions())],
            'max_product_quantity' => ['required', 'integer', 'min:0', 'max:100000'], 'max_orders_per_phone_24h' => ['required', 'integer', 'min:0', 'max:100000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:min_width=16,min_height=16,max_width=512,max_height=512,ratio=1/1'],
            'remove_logo' => ['sometimes', 'boolean'], 'remove_favicon' => ['sometimes', 'boolean']];
        foreach (['disable_auto_select_shipping', 'disable_auto_select_variants', 'disable_bot_protection'] as $field) {
            $rules[$field] = ['required', 'boolean'];
        }
        $data = $request->validate($rules);
        foreach (['logo', 'favicon'] as $field) {
            abort_if($request->hasFile($field) && ($data['remove_'.$field] ?? false), 422, 'Choose replacement or removal.');
        }
        $staged = [];
        try {
            $updated = DB::transaction(function () use ($store, $data, $request, &$staged) {
                $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($this->settings->revision($locked), $data['revision']), 409, 'Store settings changed. Reload before saving.');
                $before = $this->settings->data($locked);
                $protection = app(CheckoutBotProtection::class);
                if ($before['disable_bot_protection'] !== (bool) $data['disable_bot_protection']) {
                    $locked = $protection->save($locked, ['enabled' => ! (bool) $data['disable_bot_protection'], 'version' => $protection->configuration($locked)['version']]);
                }
                $changes = array_intersect_key($data, array_flip(['site_title', 'primary_color', 'font_family', 'default_locale', 'currency', 'timezone']));
                $changes['supported_locales'] = array_values(array_unique([$data['default_locale'], ...($locked->supported_locales ?? [])]));
                $changes['supported_currencies'] = array_values(array_unique([$data['currency'], ...($locked->supported_currencies ?? [])]));
                if (($data['header_text'] ?? '') !== ($before['header_text'] ?? '')) {
                    $changes['header_text'] = $data['header_text'];
                    $changes['header_mode'] = $data['header_text'] ? 'custom' : 'hidden';
                }
                if (bccomp((string) $data['shipping_flat_rate'], $before['shipping_flat_rate'], 2) !== 0) {
                    $changes['shipping_flat_rate'] = bcadd((string) $data['shipping_flat_rate'], '0', 2);
                    $changes['shipping_enabled'] = true;
                }
                $preferences = app(ShoppingPreferences::class)->configured($locked);
                if ($before['disable_auto_select_shipping'] !== (bool) $data['disable_auto_select_shipping'] || $before['disable_auto_select_variants'] !== (bool) $data['disable_auto_select_variants']) {
                    $changes['shopping_preferences'] = array_replace($locked->shopping_preferences ?? [], ['auto_select_variants' => ! (bool) $data['disable_auto_select_variants'], 'version' => $preferences['version'] + 1]);
                    $changes['shipping_configuration'] = array_replace(app(StoreShipping::class)->configuration($locked), ['auto_select_region' => ! (bool) $data['disable_auto_select_shipping']]);
                }
                $limits = app(OrderLimits::class)->configured($locked);
                if ($limits['phone_country'] !== $data['phone_country'] || $limits['max_product_quantity'] !== (int) $data['max_product_quantity'] || $limits['max_orders_per_phone_24h'] !== (int) $data['max_orders_per_phone_24h']) {
                    $changes['order_limits'] = array_replace($locked->order_limits ?? [], $limits, ['phone_country' => $data['phone_country'], 'max_product_quantity' => (int) $data['max_product_quantity'], 'max_orders_per_phone_24h' => (int) $data['max_orders_per_phone_24h'], 'version' => $limits['version'] + 1]);
                }
                $old = [];
                foreach (['logo', 'favicon'] as $field) {
                    if ($request->hasFile($field)) {
                        $file = $request->file($field);
                        abort_unless($file instanceof UploadedFile, 422, 'Choose a valid image.');
                        $path = $file->store('stores/'.$locked->id.'/identity', 'public');
                        abort_unless(is_string($path) && $path !== '', 500, 'Image could not be stored.');
                        $staged[] = $path;
                        $old[] = $locked->{$field};
                        $changes[$field] = $path;
                    } elseif ($data['remove_'.$field] ?? false) {
                        $old[] = $locked->{$field};
                        $changes[$field] = null;
                    }
                }
                $locked->update($changes);
                DB::afterCommit(fn () => $this->settings->cleanOldMedia($old));

                return $locked->fresh();
            });
        } catch (Throwable $error) {
            foreach ($staged as $path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $error;
        }
        $request->attributes->set('store', $updated);

        return $this->index($request);
    }
}
