<?php

namespace App\Services\StoreCatalog;

use App\Support\ProductOptionDisplay;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/** Reads inert documents only. Remote image URLs are never fetched by the server. */
class CatalogExchangeReader
{
    public const HEADERS = ['slug', 'name', 'price', 'sale_price', 'sku', 'categories', 'thumb', 'images', 'quantity', 'track_stock', 'disable_orders_for_no_stock', 'description', 'meta_description', 'variation1', 'variation2', 'variation3', 'variation4', 'variation5', 'variation6'];

    public function read(UploadedFile $file): array
    {
        if ($file->getSize() > 8 * 1024 * 1024) {
            $this->fail('Use a JSON or XLSX file smaller than 8 MiB.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'json') {
            try {
                $source = json_decode(file_get_contents($file->getRealPath()), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->fail('The file does not contain valid JSON.');
            }
            if (! is_array($source)) {
                $this->fail('Provide a product array or a Sellchaze product export.');
            }
            $rows = array_is_list($source) ? $source : ($source['products'] ?? null);
            $currency = array_is_list($source) ? null : ($source['currency'] ?? null);
        } elseif ($extension === 'xlsx') {
            $source = $this->spreadsheet($file);
            $rows = $source;
            $currency = null;
        } else {
            $this->fail('Choose a JSON or XLSX file.');
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 200) {
            $this->fail('Import between 1 and 200 products per file.');
        }
        $products = [];
        $variantCount = 0;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $this->fail('Each product must be an object.', $index);
            }
            $product = $this->normalize($row, $extension === 'xlsx', $index);
            $variantCount += count($product['variants']);
            if ($variantCount > 2000) {
                $this->fail('Import at most 2000 options per file.', $index);
            }
            $products[] = $product;
        }

        return ['products' => $products, 'currency' => $currency, 'source' => $source];
    }

    private function spreadsheet(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->fail('The XLSX archive is invalid.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 1000) {
                $this->fail('The workbook contains too many archive entries.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    $this->fail('The XLSX archive is invalid.');
                }
                $size += $entry['size'];
                if ($size > 40 * 1024 * 1024) {
                    $this->fail('The expanded workbook is larger than 40 MiB.');
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Reader;
        $rows = [];
        $headers = null;
        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                    if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $values);
                        if (count($headers) !== count(array_unique($headers)) || ! in_array('name', $headers, true) || ! in_array('price', $headers, true)) {
                            $this->fail('Use unique column headers including name and price.');
                        }

                        continue;
                    }
                    $record = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== '') {
                            $record[$header] = $values[$i] ?? '';
                        }
                    }
                    $rows[] = $record;
                    if (count($rows) > 200) {
                        $this->fail('Import at most 200 products per file.');
                    }
                }
                break;
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->fail('The XLSX file could not be read. Save it as an unencrypted XLSX workbook.');
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function normalize(array $row, bool $sheet, int $index): array
    {
        $shape = Validator::make($row, [
            'description' => ['nullable', 'string'], 'thumb' => ['nullable', 'string'], 'image_url' => ['nullable', 'string'],
            'features' => ['sometimes', 'array', 'list', 'max:100'], 'features.*' => ['array'],
            'features.*.position' => ['nullable', 'integer'], 'features.*.advanced_text' => ['nullable', 'string'],
            'variations' => ['sometimes', 'array', 'list', 'max:10'], 'variations.*' => ['array'],
            'variations.*.name' => ['required', 'string', 'max:80'], 'variations.*.type' => ['nullable', 'string'],
            'variations.*.props' => ['sometimes', 'array', 'list', 'max:200'], 'variations.*.props.*' => ['array'],
            'variations.*.props.*.id' => ['nullable', 'string'], 'variations.*.props.*.name' => ['required', 'string', 'max:120'],
            'variants' => ['sometimes', 'array', 'list', 'max:500'], 'variants.*' => ['array'],
            'variants.*.variation_props' => ['sometimes', 'array', 'list', 'max:10'], 'variants.*.variation_props.*' => ['array'],
            'variants.*.variation_props.*.variation_prop' => ['required', 'string'],
            'variants.*.variation_props.*.variation' => ['required', 'string'],
            'option_display' => ['sometimes', 'array', 'list', 'max:10'], 'option_display.*' => ['array'],
            'option_display.*.name' => ['required', 'string', 'max:80'], 'option_display.*.values' => ['required', 'array', 'list', 'max:200'],
            'option_display.*.values.*' => ['array'], 'option_display.*.values.*.value' => ['required', 'string', 'max:120'],
            'personalization_fields' => ['sometimes', 'array', 'list', 'max:10'],
        ]);
        if ($shape->fails()) {
            $this->fail($shape->errors()->first(), $index);
        }
        $external = $sheet || array_key_exists('sale_price', $row) || array_key_exists('track_stock', $row);
        $fields = ['name', 'slug', 'sku', 'barcode', 'description', 'short_description', 'price', 'compare_price', 'cost', 'weight', 'seo_title', 'seo_description', 'is_active', 'is_featured', 'position', 'translations', 'personalization_fields', 'digital_type', 'digital_url'];
        $data = Arr::only($row, $fields);
        $warnings = [];
        if ($external) {
            $regular = $row['price'] ?? null;
            $sale = $row['sale_price'] ?? null;
            $discounted = is_numeric($sale) && (float) $sale > 0 && is_numeric($regular) && (float) $sale < (float) $regular;
            $data['price'] = $discounted ? $sale : $regular;
            $data['compare_price'] = $discounted ? $regular : null;
            $data['is_active'] = ! $this->boolean($row['hidden'] ?? false, $index);
            $data['cost'] = ($row['expense'] ?? '') !== '' ? $row['expense'] : null;
            $data['seo_description'] = $row['meta_description'] ?? null;
            if ($this->boolean($row['is_digital'] ?? false, $index)) {
                $this->fail('Configure digital delivery in a Sellchaze export before importing a digital product.', $index);
            }
            if (! empty($row['features'])) {
                // Preserve readable rich content; the encrypted batch retains the complete original layout.
                $blocks = $row['features'];
                if (! is_array($blocks)) {
                    $this->fail('Product features must be an array.', $index);
                }
                usort($blocks, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
                $data['description'] = ($row['description'] ?? '').implode('', array_map(fn ($block) => is_array($block) && is_string($block['advanced_text'] ?? null) ? $block['advanced_text'] : '', $blocks));
                $warnings[] = 'review_product_layout';
            }
        } else {
            $data['is_active'] = $this->boolean($row['is_active'] ?? $row['published'] ?? true, $index);
            if (($row['variant_count'] ?? 0) > 0 && empty($row['variants'])) {
                $this->fail('This summary export has no option details. Use the full JSON product export.', $index);
            }
        }
        foreach (['sku', 'slug'] as $key) {
            if (($data[$key] ?? '') === '') {
                unset($data[$key]);
            }
        }
        $images = $this->strings($row['images'] ?? [], $index);
        $cover = $row['thumb'] ?? $row['image_url'] ?? null;
        if ($cover) {
            array_unshift($images, $cover);
        }
        $categories = $row['categories'] ?? $row['category_names'] ?? $row['category'] ?? [];
        if (is_string($categories)) {
            $categories = $this->strings($categories, $index);
        } elseif (is_array($categories) && isset($categories['name'])) {
            $categories = [$categories];
        }
        if (! is_array($categories) || ! array_is_list($categories)) {
            $this->fail('Categories must be names or category objects.', $index);
        }
        foreach ($categories as &$category) {
            if (! is_string($category) && ! is_array($category)) {
                $this->fail('Categories must contain names or category objects.', $index);
            }
            $category = is_string($category) ? ['name' => $category] : Arr::only($category, ['name', 'slug', 'description', 'show_in_header', 'position']);
        }
        unset($category);
        $axes = [];
        $personalization = $data['personalization_fields'] ?? [];
        if ($sheet) {
            for ($i = 1; $i <= 6; $i++) {
                if (! is_scalar($row['variation'.$i] ?? '') && ($row['variation'.$i] ?? null) !== null) {
                    $this->fail('Spreadsheet options must contain text.', $index);
                }
                $definition = trim((string) ($row['variation'.$i] ?? ''));
                if ($definition === '') {
                    continue;
                }
                if (! preg_match('/^(.+?)(?:\((buttons|color|image|dropdown|user_text|user_image)\))?\s*:\s*(.+)$/u', $definition, $matches)) {
                    $this->fail('Use an option such as Size: Small, Large or Color(color): Blue=#0000ff.', $index);
                }
                $axis = ['name' => trim($matches[1]), 'type' => $matches[2] ?: 'buttons', 'values' => []];
                if (in_array($axis['type'], ['user_text', 'user_image'], true)) {
                    $personalization[] = ['key' => 'field_'.$i, 'type' => $axis['type'] === 'user_text' ? 'text' : 'image', 'label' => $axis['name'], 'required' => true, 'max_length' => 1000];

                    continue;
                }
                foreach ($this->strings($matches[3], $index) as $value) {
                    $parts = explode('=', $value, 2);
                    $item = ['value' => trim($parts[0])];
                    if ($axis['type'] === 'color') {
                        $item['color'] = $parts[1] ?? null;
                    } elseif ($axis['type'] === 'image') {
                        $item['image_url'] = $parts[1] ?? null;
                    }
                    $axis['values'][] = $item;
                }
                $axes[] = $axis;
            }
        } elseif ($external) {
            foreach ($row['variations'] ?? [] as $i => $variation) {
                $type = $variation['type'] ?? 'buttons';
                if (in_array($type, ['user_text', 'user_image'], true)) {
                    $personalization[] = ['key' => 'field_'.$i, 'type' => $type === 'user_text' ? 'text' : 'image', 'label' => $variation['name'], 'required' => true, 'max_length' => 1000];

                    continue;
                }
                $axis = ['name' => $variation['name'] ?? '', 'type' => in_array($type, ['color', 'image', 'dropdown'], true) ? $type : 'buttons', 'values' => []];
                foreach ($variation['props'] ?? [] as $property) {
                    $item = ['value' => $property['name'] ?? ''];
                    if ($axis['type'] === 'color') {
                        $item['color'] = $property['value'] ?? null;
                    } elseif ($axis['type'] === 'image') {
                        $item['image_url'] = $property['value'] ?? null;
                    }
                    $axis['values'][] = $item;
                }
                $axes[] = $axis;
            }
        } else {
            $axes = $row['option_display'] ?? [];
        }
        if ($external) {
            foreach ($axes as &$axis) {
                $values = [];
                foreach ($axis['values'] as $value) {
                    $key = ProductOptionDisplay::key($value['value']);
                    if (isset($values[$key]) && Arr::except($values[$key], ['value']) !== Arr::except($value, ['value'])) {
                        $this->fail('Repeated option values have conflicting presentation settings.', $index);
                    }
                    $values[$key] ??= $value;
                }
                $axis['values'] = array_values($values);
            }
            unset($axis);
        }
        $data['personalization_fields'] = $personalization;
        $tracking = $this->boolean($row['track_stock'] ?? $row['track_inventory'] ?? (($row['stock'] ?? '') !== '' && ($row['stock'] ?? null) !== null), $index);
        if ($external && $tracking && ! $this->boolean($row['disable_orders_for_no_stock'] ?? true, $index)) {
            $warnings[] = 'review_backorders';
        }
        $quantity = $row['quantity'] ?? $row['stock_quantity'] ?? $row['stock'] ?? 0;
        $quantity = $quantity === '' || $quantity === null || (! $tracking && is_numeric($quantity) && $quantity == -1) ? 0 : $quantity;
        $variants = [];
        if ($external && ! $sheet) {
            $properties = [];
            $namedProperties = [];
            $knownCombinations = [];
            foreach ($row['variations'] ?? [] as $variation) {
                foreach ($variation['props'] ?? [] as $property) {
                    $item = ['name' => $variation['name'], 'value' => $property['name'], 'axis_id' => (string) ($variation['id'] ?? '')];
                    if (! empty($property['id'])) {
                        if (isset($properties[(string) $property['id']])) {
                            $this->fail('Variation value identities must be distinct.', $index);
                        }
                        $properties[(string) $property['id']] = $item;
                    }
                    $namedProperties[$variation['name']][$property['name']] = $item;
                }
            }
            foreach ($row['variants'] ?? [] as $variant) {
                $options = [];
                foreach ($variant['variation_props'] ?? [] as $reference) {
                    $axis = $reference['variation'];
                    $value = $reference['variation_prop'];
                    $property = $namedProperties[$axis][$value] ?? $properties[$value] ?? null;
                    if (! $property || ! in_array($axis, [$property['name'], $property['axis_id']], true) || isset($options[$property['name']])) {
                        $this->fail('An option refers to a missing variation value.', $index);
                    }
                    $options[$property['name']] = $property['value'];
                }
                $canonical = [];
                foreach ($options as $name => $value) {
                    $canonical[ProductOptionDisplay::key($name)] = ProductOptionDisplay::key($value);
                }
                ksort($canonical);
                $combination = json_encode($canonical, JSON_THROW_ON_ERROR);
                $content = Arr::except($variant, ['id', 'product_id', 'variation_props']);
                if (! $tracking && isset($knownCombinations[$combination]) && $knownCombinations[$combination] === $content) {
                    $warnings[] = 'merged_duplicate_options';

                    continue;
                }
                $knownCombinations[$combination] = $content;
                $regular = $variant['price'] ?? $row['price'];
                $sale = $variant['sale_price'] ?? null;
                $discounted = is_numeric($sale) && (float) $sale > 0 && is_numeric($regular) && (float) $sale < (float) $regular;
                $stock = $variant['quantity'] ?? $quantity;
                $stock = ! $tracking && is_numeric($stock) && $stock == -1 ? 0 : $stock;
                $variants[] = ['name' => implode(' / ', $options), 'options' => $options, 'price_override' => $discounted ? $sale : $regular, 'compare_price' => $discounted ? $regular : null, 'stock_quantity' => $stock, 'track_inventory' => $tracking, 'is_active' => true];
            }
        } elseif (! $sheet) {
            foreach ($row['variants'] ?? [] as $variant) {
                $variants[] = Arr::only($variant, ['name', 'sku', 'barcode', 'price_override', 'compare_price', 'cost', 'weight', 'options', 'translations', 'is_active', 'position', 'image_url']) + ['stock_quantity' => $variant['stock_quantity'] ?? $variant['stock'] ?? 0, 'track_inventory' => $this->boolean($variant['track_inventory'] ?? false, $index)];
            }
        }
        if ($variants === [] && $axes !== []) {
            $warnings[] = 'option_stock_per_combination';
            $combinations = [[]];
            foreach ($axes as $axis) {
                $next = [];
                foreach ($combinations as $combination) {
                    foreach ($axis['values'] ?? [] as $value) {
                        $next[] = $combination + [$axis['name'] => $value['value']];
                        if (count($next) > 500) {
                            $this->fail('A product supports at most 500 option combinations.', $index);
                        }
                    }
                }
                $combinations = $next;
            }
            foreach ($combinations as $options) {
                $variants[] = ['name' => implode(' / ', $options), 'options' => $options, 'price_override' => null, 'stock_quantity' => $quantity, 'track_inventory' => $tracking, 'is_active' => true];
            }
        }

        return ['data' => $data, 'currency' => $row['custom_currency'] ?? null, 'categories' => $categories, 'images' => array_values(array_unique($images)), 'axes' => $axes, 'variants' => $variants, 'stock_quantity' => $quantity, 'track_inventory' => $tracking, 'warnings' => array_values(array_unique($warnings))];
    }

    private function strings(mixed $value, int $index): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if (str_starts_with($value, '[')) {
                try {
                    $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->fail('A list contains invalid JSON.', $index);
                }
            } else {
                $value = preg_split('/\s*,\s*|\r?\n/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        if (! is_array($value) || ! array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value)) {
            $this->fail('Use a list of text values.', $index);
        }

        return array_values(array_filter(array_map('trim', $value), fn ($item) => $item !== ''));
    }

    private function boolean(mixed $value, int $index): bool
    {
        if (in_array($value, [true, 1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'false', '', null], true)) {
            return false;
        }
        $this->fail('Boolean cells must contain 1, 0, true or false.', $index);
    }

    private function fail(string $message, ?int $index = null): never
    {
        throw ValidationException::withMessages([$index === null ? 'file' : 'products.'.$index => $message]);
    }
}
