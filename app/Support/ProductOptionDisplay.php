<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/** Presentation metadata never changes variant identity, pricing or inventory. */
final class ProductOptionDisplay
{
    public static function rules(): array
    {
        return [
            'option_display' => ['sometimes', 'array', 'list', 'max:10'],
            'option_display.*' => ['array:name,type,labels,values'],
            'option_display.*.name' => ['required', 'string', 'max:80'],
            'option_display.*.type' => ['required', 'in:buttons,color,image,dropdown'],
            'option_display.*.labels' => ['sometimes', 'array:ar,en'],
            'option_display.*.labels.*' => ['nullable', 'string', 'max:120'],
            'option_display.*.values' => ['present', 'array', 'list', 'max:200'],
            'option_display.*.values.*' => ['array:value,labels,color,media_id'],
            'option_display.*.values.*.value' => ['required', 'string', 'max:120'],
            'option_display.*.values.*.labels' => ['sometimes', 'array:ar,en'],
            'option_display.*.values.*.labels.*' => ['nullable', 'string', 'max:120'],
            'option_display.*.values.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'option_display.*.values.*.media_id' => ['nullable', 'integer'],
        ];
    }

    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public static function validate(Product $product, array $display, array $removedIds): void
    {
        $known = [];
        foreach ($product->variants()->get() as $variant) {
            foreach ($variant->options ?? [] as $name => $value) {
                $known[self::key($name)][self::key($value)] = true;
            }
        }
        $images = $product->media()->whereIn('type', ['cover', 'gallery'])->whereNotIn('id', $removedIds)->pluck('id')->all();
        $names = [];
        foreach ($display as $axis) {
            $name = self::key($axis['name']);
            if (isset($names[$name]) || ! isset($known[$name])) {
                throw ValidationException::withMessages(['option_display' => 'Choose each existing variant property only once. Refresh the product options.']);
            }
            $names[$name] = true;
            $values = [];
            foreach ($axis['values'] as $item) {
                $value = self::key($item['value']);
                if (isset($values[$value]) || ! isset($known[$name][$value])) {
                    throw ValidationException::withMessages(['option_display' => 'Choose each existing property value only once. Refresh the product options.']);
                }
                $values[$value] = true;
                if (isset($item['media_id']) && ! in_array((int) $item['media_id'], $images, true)) {
                    throw ValidationException::withMessages(['option_display' => 'Option images must belong to this product and remain in its gallery.']);
                }
            }
        }
    }

    public static function publicPayload(Product $product): array
    {
        $locale = app()->getLocale();
        $images = $product->relationLoaded('media') ? $product->media->keyBy('id') : collect();

        return array_map(function ($axis) use ($locale, $images) {
            return ['name' => $axis['name'], 'label' => $axis['labels'][$locale] ?? $axis['name'], 'type' => $axis['type'],
                'values' => array_map(function ($item) use ($locale, $images) {
                    $image = $images->get($item['media_id'] ?? null);

                    return ['value' => $item['value'], 'label' => $item['labels'][$locale] ?? $item['value'],
                        'color' => $item['color'] ?? null, 'image_url' => $image && in_array($image->type, ['cover', 'gallery'], true) ? $image->url() : null];
                }, $axis['values'])];
        }, $product->option_display ?? []);
    }
}
