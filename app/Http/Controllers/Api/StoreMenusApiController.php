<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreMenu;
use App\Services\PageBuilder\StoreMenuService;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Navigation menus (Tasks 4 & 5): header/footer/custom menus with nested, typed
 * items (internal | category | product | url). Owner/admin, tenant-scoped.
 */
class StoreMenusApiController extends Controller
{
    public function __construct(private readonly StoreMenuService $service) {}

    /** GET /stores/{store}/menus */
    public function index(Request $request, Store $store): JsonResponse
    {
        $menus = StoreMenu::query()->where('store_id', $store->id)->orderBy('handle')->get()
            ->map(fn (StoreMenu $m) => ['id' => $m->id, 'handle' => $m->handle, 'name' => $m->name]);

        return response()->json(['data' => $menus], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/menus/{handle} — resolved nested tree. */
    public function show(Request $request, Store $store, string $handle): JsonResponse
    {
        $menu = StoreMenu::query()->where('store_id', $store->id)->where('handle', $handle)->first();
        abort_if($menu === null, 404, 'Menu not found.');

        return response()->json([
            'menu' => ['id' => $menu->id, 'handle' => $menu->handle, 'name' => $menu->name],
            'items' => $this->service->tree($menu),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * PUT /stores/{store}/menus/{handle} — upsert menu + replace its item tree.
     * Item labels are a string or a `{locale: label}` map (nested children included).
     */
    public function upsert(Request $request, Store $store, string $handle): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9\-]+$/', $handle), 422, 'Invalid handle.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['present', 'array'],
            'items.*' => ['array'],
            'items.*.label' => ['required', $this->labelRule(LocaleContext::storeSupported($store))],
        ]);
        // validated() narrows `items.*` to the keyed rules; the full item tree (type/target/children) comes from input.
        $items = array_values(array_filter((array) $request->input('items', []), 'is_array'));
        $this->validateNestedLabels($items, LocaleContext::storeSupported($store), 'items');

        $menu = $this->service->upsertMenu($store, $handle, $data['name']);
        $menu = $this->service->syncItems($menu, $items);

        return response()->json([
            'menu' => ['id' => $menu->id, 'handle' => $menu->handle, 'name' => $menu->name],
            'items' => $this->service->tree($menu),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** @param  list<string>  $locales */
    private function labelRule(array $locales): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($locales): void {
            if (is_string($value)) {
                if (trim($value) === '' || mb_strlen($value) > 255) {
                    $fail("The {$attribute} must be a non-empty label of at most 255 characters.");
                }

                return;
            }
            if (! is_array($value)) {
                $fail("The {$attribute} must be a string or a {locale: label} object.");

                return;
            }
            foreach ($value as $locale => $label) {
                if (! is_string($locale) || (! in_array($locale, $locales, true) && $locale !== LocalizedValue::DEFAULT_KEY)) {
                    $fail("The {$attribute} contains an unsupported locale '{$locale}'.");

                    return;
                }
                if ($label !== null && (! is_string($label) || mb_strlen($label) > 255)) {
                    $fail("The {$attribute}.{$locale} must be a label of at most 255 characters.");

                    return;
                }
            }
            if (LocalizedValue::normalize($value, $locales[0] ?? 'en') === []) {
                $fail("The {$attribute} needs a label in at least one locale.");
            }
        };
    }

    /** Children are arbitrarily nested, so their labels are checked recursively rather than by fixed-depth rules. */
    private function validateNestedLabels(array $items, array $locales, string $path): void
    {
        $rule = $this->labelRule($locales);
        foreach ($items as $index => $item) {
            $children = $item['children'] ?? null;
            if (! is_array($children)) {
                continue;
            }
            foreach ($children as $childIndex => $child) {
                $attribute = "{$path}.{$index}.children.{$childIndex}.label";
                if (! is_array($child) || ! array_key_exists('label', $child)) {
                    throw ValidationException::withMessages([$attribute => "The {$attribute} field is required."]);
                }
                $rule($attribute, $child['label'], function (string $message) use ($attribute): void {
                    throw ValidationException::withMessages([$attribute => $message]);
                });
            }
            $this->validateNestedLabels($children, $locales, "{$path}.{$index}.children");
        }
    }

    public function destroy(Request $request, Store $store, string $handle): JsonResponse
    {
        $menu = StoreMenu::query()->where('store_id', $store->id)->where('handle', $handle)->first();
        abort_if($menu === null, 404);
        $this->service->delete($menu);

        return response()->json(['message' => 'Deleted.'], 200);
    }
}
