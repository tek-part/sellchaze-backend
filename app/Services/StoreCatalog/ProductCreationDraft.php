<?php

namespace App\Services\StoreCatalog;

use App\Http\Requests\StorefrontProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreCatalogOperation;
use App\Services\Commerce\StoreInventory;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Initial editorial options and stock are committed with their new product. */
final class ProductCreationDraft
{
    public static function replayProductId(mixed $key): ?int
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            return null;
        }
        $receipt = StoreCatalogOperation::query()->where('idempotency_key', $key)->where('operation', 'editor-create')->first();

        return $receipt ? (int) $receipt->result['id'] : null;
    }

    public static function rules(?int $replayId = null): array
    {
        $rules = [
            'creation_key' => ['required_with:creation', 'uuid'],
            'creation' => ['sometimes', 'array:variants,inventory,gallery_images'],
            'creation.gallery_images' => ['sometimes', 'array', 'list', 'max:200'],
            'creation.gallery_images.*' => ['array:axis,value,index'],
            'creation.gallery_images.*.axis' => ['required', 'string', 'max:80'],
            'creation.gallery_images.*.value' => ['required', 'string', 'max:120'],
            'creation.gallery_images.*.index' => ['required', 'integer', 'min:0', 'max:11'],
            'creation.inventory' => ['required_with:creation', 'array:track_inventory,stock_quantity'],
            'creation.inventory.track_inventory' => ['required_with:creation', 'boolean'],
            'creation.inventory.stock_quantity' => ['required_with:creation', 'integer', 'min:0', 'max:100000000'],
            'creation.variants' => ['present_with:creation', 'array', 'list', 'max:200'],
            'creation.variants.*' => ['array:name,sku,barcode,price_override,compare_price,cost,weight,options,is_active,position,track_inventory,stock_quantity,translations'],
            'creation.variants.*.track_inventory' => ['required', 'boolean'],
            'creation.variants.*.stock_quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
        foreach ((new StorefrontProductVariantRequest)->rules() as $key => $value) {
            if (! in_array($key, ['image_media_id', 'edit_version'], true)) {
                $rules['creation.variants.*.'.$key] = $value;
            }
        }
        $rules['creation.variants.*.sku'] = ['nullable', 'string', 'max:120', Rule::unique('store_product_variants', 'sku')
            ->where('store_id', app(CurrentStore::class)->id())->when($replayId, fn ($rule) => $rule->whereNot('store_product_id', $replayId))];

        return $rules;
    }

    public function apply(Product $product, array $draft, int $actorId): void
    {
        if ($draft['variants'] !== [] && (int) $draft['inventory']['stock_quantity'] !== 0) {
            throw ValidationException::withMessages(['creation.inventory' => 'Allocate stock to the individual options; base stock must be zero for a product with options.']);
        }
        $skus = [];
        foreach ($draft['variants'] as $data) {
            if (! empty($data['sku'])) {
                $sku = mb_strtolower(trim($data['sku']));
                if (isset($skus[$sku])) {
                    throw ValidationException::withMessages(['creation.variants' => 'Option SKUs must be distinct.']);
                }
                $skus[$sku] = true;
            }
            $variant = app(StorefrontProductVariantService::class)->create($product, $data, false);
            $this->stock($variant->refresh(), $data, $actorId);
        }
        $this->stock($product->refresh(), $draft['inventory'], $actorId);
    }

    public function displayImages(array $display, array $references, array $uploadedMedia): array
    {
        $known = [];
        foreach ($references as $reference) {
            $media = $uploadedMedia[$reference['index']] ?? null;
            $matched = false;
            foreach ($display as &$axis) {
                if ($axis['name'] !== $reference['axis'] || $axis['type'] !== 'image') {
                    continue;
                }
                foreach ($axis['values'] as &$value) {
                    if ($value['value'] === $reference['value'] && $media && $media->type === 'gallery') {
                        $key = json_encode([$axis['name'], $value['value']]);
                        if (isset($known[$key])) {
                            throw ValidationException::withMessages(['creation.gallery_images' => 'Choose one image per property value.']);
                        }
                        $known[$key] = true;
                        $value['media_id'] = $media->id;
                        $matched = true;
                    }
                }
                unset($value);
            }
            unset($axis);
            if (! $matched) {
                throw ValidationException::withMessages(['creation.gallery_images' => 'Choose an uploaded image for an existing image property value.']);
            }
        }

        return $display;
    }

    private function stock(Product|ProductVariant $stock, array $input, int $actorId): void
    {
        app(StoreInventory::class)->adjust($stock, [
            'track_inventory' => (bool) $input['track_inventory'], 'stock_quantity' => (int) $input['stock_quantity'],
            'expected_stock' => $stock->stock_quantity, 'expected_reserved' => $stock->reserved_quantity,
            'expected_tracking' => $stock->track_inventory, 'note' => 'Initial product editor allocation',
        ], $actorId, false);
    }
}
