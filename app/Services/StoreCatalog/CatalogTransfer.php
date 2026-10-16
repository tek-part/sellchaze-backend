<?php

namespace App\Services\StoreCatalog;

use App\Http\Requests\StorefrontProductStoreRequest;
use App\Http\Requests\StorefrontProductVariantRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCatalogOperation;
use App\Models\User;
use App\Services\Commerce\ProductPreferences;
use App\Services\Commerce\StoreInventory;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\CatalogTransferVersion;
use App\Support\ProductOptionDisplay;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Bounded, tenant-scoped catalog transfers. Imports create new identities only. */
class CatalogTransfer
{
    public function preview(UploadedFile $file, Store $store, User $actor): array
    {
        $document = app(CatalogExchangeReader::class)->read($file);
        $this->validateDocument($document, $store, $actor);

        return ['products' => array_map(fn ($plan) => ['name' => $plan['data']['name'], 'price' => $plan['data']['price'],
            'variants' => count($plan['variants']), 'categories' => array_column($plan['categories'], 'name'),
            'images' => count($plan['images']), 'is_active' => $plan['data']['is_active'], 'warnings' => $plan['warnings'],
            'stock_quantity' => $plan['stock_quantity'], 'track_inventory' => $plan['track_inventory'],
            'options' => array_map(fn ($variant) => ['name' => $variant['name'], 'price' => $variant['price_override'] ?? $plan['data']['price'],
                'stock_quantity' => $variant['stock_quantity'], 'track_inventory' => $variant['track_inventory']], $plan['variants'])], $document['products']),
            'currency' => $store->currency, 'count' => count($document['products'])];
    }

    public function import(UploadedFile $file, string $key, Store $store, User $actor): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        return $this->operation($store, $actor, $key, 'import', $hash, function () use ($file, $store, $actor) {
            $document = app(CatalogExchangeReader::class)->read($file);
            $this->validateDocument($document, $store, $actor);
            $ids = [];
            foreach ($document['products'] as $plan) {
                $ids[] = $this->create($plan, $store, $actor)->id;
            }

            return [['created' => count($ids), 'ids' => $ids], $document['source']];
        });
    }

    private function validateDocument(array $document, Store $store, User $actor): void
    {
        $this->currency($document['currency'], $store);
        $skus = $variantSkus = [];
        $variantCount = 0;
        foreach ($document['products'] as $index => $plan) {
            try {
                $this->currency($plan['currency'], $store);
                Validator::make($plan['data'], ['price' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
                    'digital_url' => ['required_if:digital_type,link', 'nullable', 'url:http,https', 'max:2000']]
                    + (new StorefrontProductStoreRequest)->rules())->validate();
                ProductPreferences::canonical($plan['data']['product_preferences'] ?? null);
                if (isset($plan['data']['landing_page'])) {
                    ProductLandingPages::canonical($plan['data']['landing_page']);
                }
                Validator::make($plan, [
                    'categories' => ['array', 'max:20'], 'categories.*.name' => ['required', 'string', 'max:255'],
                    'categories.*.slug' => ['nullable', 'string', 'max:255'], 'categories.*.description' => ['nullable', 'string', 'max:20000'],
                    'categories.*.show_in_header' => ['nullable', 'boolean'], 'categories.*.position' => ['nullable', 'integer', 'min:0'],
                    'images' => ['array', 'max:100'], 'images.*' => ['required', 'url:http,https', 'max:2000'],
                    'stock_quantity' => ['required', 'integer', 'min:0', 'max:100000000'], 'track_inventory' => ['required', 'boolean'],
                    'axes' => ['array', 'list', 'max:10'], 'axes.*.name' => ['required', 'string', 'max:80'],
                    'axes.*.type' => ['required', 'in:buttons,color,image,dropdown'], 'axes.*.values' => ['required', 'array', 'list', 'min:1', 'max:200'],
                    'axes.*.values.*.value' => ['required', 'string', 'max:120'],
                    'axes.*.values.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                    'axes.*.values.*.image_url' => ['nullable', 'url:http,https', 'max:2000'],
                    'variants' => ['array', 'list', 'max:500'],
                ])->validate();
                $this->uniqueSku($plan['data']['sku'] ?? null, $skus);
                foreach ($plan['categories'] as $category) {
                    if (! Category::query()->where('name', $category['name'])->exists()) {
                        abort_unless($actor->can('categories-create'), 403, 'Creating imported categories requires categories-create.');
                    }
                }
                $known = [];
                foreach ($plan['variants'] as $variant) {
                    Validator::make($variant, (new StorefrontProductVariantRequest)->rules() + [
                        'stock_quantity' => ['required', 'integer', 'min:0', 'max:100000000'], 'track_inventory' => ['required', 'boolean'],
                        'image_url' => ['nullable', 'url:http,https', 'max:2000'],
                    ])->validate();
                    $this->uniqueSku($variant['sku'] ?? null, $variantSkus);
                    $options = [];
                    foreach ($variant['options'] ?? [] as $name => $value) {
                        $options[ProductOptionDisplay::key($name)] = ProductOptionDisplay::key($value);
                    }
                    ksort($options);
                    $signature = json_encode($options, JSON_THROW_ON_ERROR);
                    if (isset($known[$signature])) {
                        throw ValidationException::withMessages(['variants' => 'Each option combination must be distinct.']);
                    }
                    $known[$signature] = true;
                }
                // Validate display identities before writing anything, including preview requests.
                $display = $this->display($plan['axes'], []);
                Validator::make(['option_display' => $display], ProductOptionDisplay::rules())->validate();
                $names = [];
                foreach ($display as $axis) {
                    $name = ProductOptionDisplay::key($axis['name']);
                    if (isset($names[$name])) {
                        throw ValidationException::withMessages(['axes' => 'Property names must be distinct.']);
                    }
                    $names[$name] = true;
                    $values = [];
                    foreach ($axis['values'] as $value) {
                        $valueKey = ProductOptionDisplay::key($value['value']);
                        if (isset($values[$valueKey]) || ! collect($plan['variants'])->contains(fn ($variant) => collect($variant['options'] ?? [])->contains(fn ($v, $n) => ProductOptionDisplay::key($n) === $name && ProductOptionDisplay::key($v) === $valueKey))) {
                            throw ValidationException::withMessages(['axes' => 'Display properties must refer to distinct imported option values.']);
                        }
                        $values[$valueKey] = true;
                    }
                }
                $variantCount += count($plan['variants']);
                if ($variantCount > 2000) {
                    throw ValidationException::withMessages(['variants' => 'Import at most 2000 options per file.']);
                }
            } catch (ValidationException $error) {
                $errors = [];
                foreach ($error->errors() as $field => $messages) {
                    $errors['products.'.$index.'.'.$field] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }
    }

    private function currency(mixed $currency, Store $store): void
    {
        if ($currency !== null && $currency !== '' && (! is_string($currency) || strtoupper($currency) !== strtoupper($store->currency))) {
            throw ValidationException::withMessages(['currency' => 'Product currency must match the store; prices are never converted automatically.']);
        }
    }

    private function uniqueSku(?string $sku, array &$known): void
    {
        if ($sku === null || $sku === '') {
            return;
        }
        $key = mb_strtolower($sku);
        if (isset($known[$key])) {
            throw ValidationException::withMessages(['sku' => 'Each SKU must be distinct within the imported file.']);
        }
        $known[$key] = true;
    }

    private function create(array $plan, Store $store, User $actor): Product
    {
        $ids = [];
        foreach ($plan['categories'] as $category) {
            $ids[] = (Category::query()->where('name', $category['name'])->first()
                ?? app(StorefrontCategoryService::class)->create($category + ['is_active' => true]))->id;
        }
        $data = $plan['data'];
        // Explicit canonical copy must win over stale default-locale translations in edited exports.
        foreach (['name', 'description', 'short_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $data['translations'][$field][$store->default_locale ?: 'ar'] = $data[$field];
            }
        }
        $product = app(StorefrontProductService::class)->create($data + ['category_id' => $ids[0] ?? null, 'category_ids' => $ids]);
        if (isset($data['landing_page'])) {
            $product->landing_page = ProductLandingPages::canonical($data['landing_page']);
            $product->save();
        }
        $images = $plan['images'];
        foreach ($plan['axes'] as $axis) {
            foreach ($axis['values'] as $value) {
                if (! empty($value['image_url'])) {
                    $images[] = $value['image_url'];
                }
            }
        }
        foreach ($plan['variants'] as $variant) {
            if (! empty($variant['image_url'])) {
                $images[] = $variant['image_url'];
            }
        }
        $images = array_values(array_unique($images));
        if (count($images) > 100) {
            throw ValidationException::withMessages(['images' => 'A product supports at most 100 images, including option images.']);
        }
        $media = [];
        foreach ($images as $position => $url) {
            $media[$url] = ProductMedia::create(['store_id' => $store->id, 'store_product_id' => $product->id,
                'type' => 'gallery', 'disk' => 'public', 'path' => $url, 'alt' => $product->name, 'position' => $position])->id;
        }
        $product->image = $plan['images'][0] ?? null;
        $product->save();
        foreach ($plan['variants'] as $position => $variant) {
            $model = app(StorefrontProductVariantService::class)->create($product, $variant + ['position' => $position,
                'image_media_id' => $media[$variant['image_url'] ?? ''] ?? null]);
            $this->stock($model, $variant, $actor);
        }
        if ($plan['variants'] === []) {
            $this->stock($product->refresh(), $plan, $actor);
        }
        $display = $this->display($plan['axes'], $media);
        ProductOptionDisplay::validate($product, $display, []);
        $product->option_display = $display;
        $product->save();

        return $product;
    }

    private function stock(Product|ProductVariant $model, array $input, User $actor): void
    {
        app(StoreInventory::class)->adjust($model, ['stock_quantity' => (int) $input['stock_quantity'], 'track_inventory' => (bool) $input['track_inventory'],
            'expected_stock' => $model->stock_quantity, 'expected_reserved' => $model->reserved_quantity,
            'expected_tracking' => $model->track_inventory, 'note' => 'Catalog import / duplicate'], $actor->id, false);
    }

    private function display(array $axes, array $media): array
    {
        return array_map(fn ($axis) => Arr::only($axis, ['name', 'type', 'labels']) + ['values' => array_map(fn ($value) => Arr::only($value, ['value', 'color', 'labels']) + ['media_id' => $media[$value['image_url'] ?? ''] ?? null], $axis['values'])], $axes);
    }

    public function export(array $targets): array
    {
        return DB::transaction(function () use ($targets) {
            $products = $this->selection($targets, true);
            if ($products->sum(fn ($product) => $product->variants()->count()) > 2000) {
                throw ValidationException::withMessages(['products' => 'Select fewer products: portable exports support at most 2000 options per file.']);
            }
            $rows = $products->map(fn ($product) => $this->portable($product))->all();
            if (strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 7 * 1024 * 1024) {
                throw ValidationException::withMessages(['products' => 'Select fewer products to keep the export within the import size limit.']);
            }

            return $rows;
        });
    }

    private function portable(Product $product): array
    {
        $product->load(['category', 'additionalCategories', 'variants.imageMedia', 'media']);
        $data = Arr::only($product->getAttributes(), ['name', 'slug', 'sku', 'barcode', 'description', 'short_description', 'price', 'compare_price', 'cost', 'weight', 'seo_title', 'seo_description', 'position', 'digital_type']);
        $data['description'] = $product->long_description ?? $product->description;
        $data += ['is_active' => $product->is_active, 'is_featured' => $product->is_featured, 'translations' => $product->translationsPayload(),
            'product_preferences' => $product->product_preferences, 'display_priority' => $product->display_priority, 'catalog_hidden' => $product->catalog_hidden,
            'landing_page' => ProductLandingPages::portablePayload($product),
            'personalization_fields' => $product->personalization_fields ?? [], 'image_url' => $this->absolute($product->imageUrl()),
            'images' => $product->media->whereIn('type', ['cover', 'gallery'])->map(fn ($media) => $this->absolute($media->url()))->filter()->values()->all(),
            'categories' => $product->additionalCategories->merge($product->category ? [$product->category] : [])->unique('id')->map(fn ($category) => ['name' => $category->name, 'slug' => $category->slug])->values()->all(),
            // Export available units only; reservations remain attached to the original identities.
            'stock_quantity' => max(0, $product->stock_quantity - $product->reserved_quantity), 'track_inventory' => $product->track_inventory,
            'variants' => $product->variants->map(fn ($variant) => Arr::only($variant->getAttributes(), ['name', 'sku', 'barcode', 'price_override', 'compare_price', 'cost', 'weight', 'position']) + [
                'options' => $variant->options ?? [], 'is_active' => $variant->is_active, 'translations' => $variant->translationsPayload(),
                'stock_quantity' => max(0, $variant->stock_quantity - $variant->reserved_quantity), 'track_inventory' => $variant->track_inventory,
                'image_url' => $this->absolute($variant->imageUrl()),
            ])->all(), 'option_display' => array_map(fn ($axis) => Arr::only($axis, ['name', 'type', 'labels']) + ['values' => array_map(fn ($value) => Arr::only($value, ['value', 'labels', 'color']) + ['image_url' => $this->absolute($product->media->firstWhere('id', $value['media_id'] ?? null)?->url())], $axis['values'])], $product->option_display ?? [])];
        // Digital delivery credentials and code pools never enter a portable document.
        if ($product->digital_type !== 'physical') {
            $data['is_active'] = false;
            $data['stock_quantity'] = 0;
            $data['track_inventory'] = true;
            foreach ($data['variants'] as &$variant) {
                $variant['stock_quantity'] = 0;
                $variant['track_inventory'] = true;
            }
            unset($variant);
        }

        return $data;
    }

    public function duplicate(array $target, string $key, Store $store, User $actor): array
    {
        return $this->operation($store, $actor, $key, 'duplicate', hash('sha256', json_encode($target, JSON_THROW_ON_ERROR)), function () use ($target, $store, $actor) {
            $source = $this->selection([$target], true)->first();
            abort_unless($actor->can('view', $source), 403);
            $copy = $source->replicate(['sku', 'barcode', 'slug', 'rating_avg', 'rating_count', 'sales_count', 'views_count']);
            $copy->setRelations([]);
            $copy->name = mb_substr($source->name, 0, 245).' (copy)';
            foreach ($copy->translationsFor('name') as $locale => $name) {
                $copy->setTranslations('name', array_merge($copy->translationsFor('name'), [$locale => mb_substr($name, 0, 245).' (copy)']));
            }
            $copy->slug = Slug::unique(($source->slug ?: 'product').'-copy', fn ($slug) => Product::query()->where('slug', $slug)->exists(), 'product');
            $copy->is_active = false;
            $copy->stock_quantity = $copy->reserved_quantity = 0;
            $copy->save();
            $copy->additionalCategories()->sync(array_fill_keys($source->additionalCategories->pluck('id')->all(), ['store_id' => $store->id]));
            $mediaIds = [];
            foreach ($source->media as $original) {
                $media = $original->replicate();
                $media->setRelations([]);
                $media->store_product_id = $copy->id;
                $media->store_product_variant_id = null;
                $media->save();
                $mediaIds[$original->id] = $media->id;
            }
            $variantIds = [];
            foreach ($source->variants as $original) {
                $variant = $original->replicate(['sku', 'barcode']);
                $variant->setRelations([]);
                $variant->store_product_id = $copy->id;
                $variant->image_media_id = $mediaIds[$original->image_media_id] ?? null;
                $variant->stock_quantity = $variant->reserved_quantity = 0;
                $variant->save();
                $variantIds[$original->id] = $variant->id;
                $this->stock($variant->refresh(), ['stock_quantity' => 0, 'track_inventory' => $variant->track_inventory], $actor);
            }
            foreach ($source->media as $original) {
                if ($original->store_product_variant_id) {
                    $copy->media()->whereKey($mediaIds[$original->id])->update(['store_product_variant_id' => $variantIds[$original->store_product_variant_id] ?? null]);
                }
            }
            $display = $source->option_display ?? [];
            foreach ($display as &$axis) {
                foreach ($axis['values'] as &$value) {
                    $value['media_id'] = $mediaIds[$value['media_id'] ?? null] ?? null;
                }
                unset($value);
            }
            unset($axis);
            $copy->option_display = $display;
            if ($source->landing_page !== null) {
                $remap = function (array $value) use (&$remap, $mediaIds): array {
                    if (isset($value['media_id'])) {
                        $value['media_id'] = $mediaIds[$value['media_id']] ?? $value['media_id'];
                    }
                    foreach ($value as $key => $child) {
                        if (is_array($child)) {
                            $value[$key] = $remap($child);
                        }
                    }

                    return $value;
                };
                $copy->landing_page = ProductLandingPages::canonical($remap($source->landing_page), $copy);
            }
            $copy->save();
            if ($source->variants->isEmpty()) {
                $this->stock($copy->refresh(), ['stock_quantity' => 0, 'track_inventory' => $copy->track_inventory], $actor);
            }
            DB::afterCommit(function () use ($store) {
                StorefrontService::forgetHomepage((int) $store->id);
                app(StorefrontPageCache::class)->flushStore((int) $store->id);
            });

            return [['id' => $copy->id, 'created' => 1], null];
        });
    }

    public function delete(array $targets, string $key, Store $store, User $actor): array
    {
        return $this->operation($store, $actor, $key, 'delete', hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR)), function () use ($targets, $actor) {
            $products = $this->selection($targets, true);
            foreach ($products as $product) {
                abort_unless($actor->can('delete', $product), 403);
                app(StorefrontProductService::class)->delete($product);
            }

            return [['deleted' => $products->count()], null];
        });
    }

    /** @return Collection<int, Product> */
    private function selection(array $targets, bool $lock = false): Collection
    {
        $products = Product::query()->whereIn('id', array_column($targets, 'id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($products->count() !== count($targets)) {
            throw ValidationException::withMessages(['products' => 'Refresh the list: a selected product is unavailable.']);
        }
        $versions = array_column($targets, 'version', 'id');
        foreach ($products as $product) {
            if (! hash_equals(CatalogTransferVersion::for($product), $versions[$product->id])) {
                throw ValidationException::withMessages(['products' => 'A selected product changed. Refresh the list before trying again.']);
            }
        }

        return $products;
    }

    private function absolute(?string $url): ?string
    {
        return $url === null ? null : (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : url($url));
    }

    private function operation(Store $store, User $actor, string $key, string $kind, string $hash, callable $work): array
    {
        return DB::transaction(function () use ($store, $actor, $key, $kind, $hash, $work) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $previous = StoreCatalogOperation::query()->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless($previous->operation === $kind && hash_equals($previous->payload_hash, $hash), 409, 'This operation key was already used with different content.');

                return $previous->result;
            }
            [$result, $source] = $work();
            StoreCatalogOperation::create(['store_id' => $store->id, 'actor_id' => $actor->id, 'idempotency_key' => $key,
                'operation' => $kind, 'payload_hash' => $hash, 'source_payload' => $source, 'result' => $result]);

            return $result;
        });
    }
}
