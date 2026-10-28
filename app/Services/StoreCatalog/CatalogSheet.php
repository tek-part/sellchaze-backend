<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\StoreInventory;
use App\Support\ProductOptionDisplay;
use App\Support\XlsxArchiveGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Flat product sheets (XLSX/CSV): one row per option with its option values, SKU, prices and stock.
 * Exports round-trip into an "update existing" import (matched by id or SKU) or a "create new" import.
 * Planning never writes; it returns a per-row report so merchants can fix errors before applying.
 */
class CatalogSheet
{
    /** `categories` is a readable copy of `category_ids` and is ignored on import; ids are authoritative. */
    public const PRODUCT_HEADERS = ['product_id', 'variant_id', 'slug', 'name', 'status', 'category_ids', 'categories', 'price', 'compare_price', 'sku', 'stock', 'track_inventory'];

    /** A blank cell keeps the current value; this token clears an optional value (compare-at price, SKU, option price, categories). */
    public const CLEAR = '[clear]';

    public const VARIANT_HEADERS = ['variant_sku', 'variant_price', 'variant_compare_price', 'variant_status'];

    public const MAX_ROWS = 2000;

    public const MAX_EXPORT_ROWS = 10000;

    private const MAX_PRICE = 999999999.99;

    // ---------------------------------------------------------------- export

    /** @return array{0: string, 1: string} temporary file path and download name */
    public function export(?array $productIds, ?int $categoryId, string $format): array
    {
        $products = fn () => Product::query()
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))
            ->when($categoryId !== null, fn ($q) => $q->inCategories([$categoryId]));
        // Count rows in SQL before loading anything: one row per option, or one row for a product without options.
        $productCount = $products()->count();
        if ($productIds !== null && $productCount !== count(array_unique($productIds))) {
            throw ValidationException::withMessages(['products' => 'Refresh the list: a selected product is unavailable.']);
        }
        $variants = fn () => ProductVariant::query()->whereIn('store_product_id', $products()->select('id'));
        $rowCount = $productCount - $variants()->distinct()->count('store_product_id') + $variants()->count();
        if ($rowCount > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['products' => 'Sheet exports support at most '.self::MAX_EXPORT_ROWS.' rows. Export one category or a smaller selection.']);
        }
        $axes = 3;
        foreach ($variants()->select(['id', 'options'])->lazyById(1000) as $variant) {
            $axes = max($axes, min(10, count($variant->options ?? [])));
        }
        $headers = $this->headers($axes);
        $path = tempnam(sys_get_temp_dir(), 'catalog-sheet-');
        $stream = $writer = null;
        try {
            if ($format === 'csv') {
                $stream = fopen($path, 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $headers, ',', '"', '');
            } else {
                $writer = new Writer;
                $writer->openToFile($path);
                $writer->addRow($this->xlsxRow($headers));
            }
            foreach ($products()->with(['variants', 'category', 'additionalCategories'])->lazyById(200) as $product) {
                foreach ($this->rows($product, $axes) as $row) {
                    $stream ? fputcsv($stream, array_map(fn ($value) => is_string($value) ? self::escapeCsv($value) : (string) ($value ?? ''), $row), ',', '"', '')
                        : $writer->addRow($this->xlsxRow($row));
                }
            }
            $stream ? fclose($stream) : $writer->close();
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return [$path, 'sellchaze-products-'.now()->format('Y-m-d').'.'.$format];
    }

    /** Text cells are always written as strings, so "=..." can never become a spreadsheet formula. */
    private function xlsxRow(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }

    /** CSV formula guard: a leading quote is added to risky text (and to text that already starts with one) and removed on import. */
    public static function escapeCsv(string $value): string
    {
        $value = str_replace("\0", '', $value);

        return preg_match("/^\\s*[=+\\-@\\t\\r']/u", $value) ? "'".$value : $value;
    }

    public static function unescapeCsv(string $value): string
    {
        return preg_match("/^'\\s*[=+\\-@\\t\\r']/u", $value) ? substr($value, 1) : $value;
    }

    private function headers(int $axes): array
    {
        $options = [];
        for ($i = 1; $i <= $axes; $i++) {
            $options[] = 'option'.$i.'_name';
            $options[] = 'option'.$i.'_value';
        }

        return array_merge(self::PRODUCT_HEADERS, $options, self::VARIANT_HEADERS);
    }

    /** @return list<list<mixed>> */
    private function rows(Product $product, int $axes): array
    {
        $digital = in_array($product->digital_type, ['codes', 'link'], true);
        $categories = collect([$product->category])->merge($product->additionalCategories)->filter()->unique('id')->values();
        $base = [$product->id, null, $product->slug, $product->name, $product->is_active ? 'active' : 'draft',
            $categories->pluck('id')->implode(','), $categories->pluck('name')->implode(' | '),
            (float) $product->price, $product->compare_price === null ? null : (float) $product->compare_price, $product->sku];
        if ($product->variants->isEmpty()) {
            return [array_merge($base, [$digital ? null : $product->stock_quantity, $digital ? null : ($product->track_inventory ? 1 : 0)],
                array_fill(0, $axes * 2, null), [null, null, null, null])];
        }
        $order = array_map(fn ($axis) => ProductOptionDisplay::key($axis['name']), $product->option_display ?? []);
        $rows = [];
        foreach ($product->variants as $variant) {
            $options = $variant->options ?? [];
            uksort($options, function ($a, $b) use ($order) {
                $left = array_search(ProductOptionDisplay::key($a), $order, true);
                $right = array_search(ProductOptionDisplay::key($b), $order, true);

                return ($left === false ? PHP_INT_MAX : $left) <=> ($right === false ? PHP_INT_MAX : $right);
            });
            $cells = [];
            foreach ($options as $name => $value) {
                $cells[] = $name;
                $cells[] = $value;
            }
            $row = $base;
            $row[1] = $variant->id;
            $rows[] = array_merge($row, [$digital ? null : $variant->stock_quantity, $digital ? null : ($variant->track_inventory ? 1 : 0)],
                array_pad(array_slice($cells, 0, $axes * 2), $axes * 2, null),
                [$variant->sku, $variant->price_override === null ? null : (float) $variant->price_override,
                    $variant->compare_price === null ? null : (float) $variant->compare_price, $variant->is_active ? 'active' : 'draft']);
        }

        return $rows;
    }

    // ---------------------------------------------------------------- read

    /** @return array{headers: list<string>, rows: list<array{row: int, cells: array<string, string>}>} */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($file->getSize() > 8 * 1024 * 1024) {
            $this->fail('Use a CSV or XLSX file smaller than 8 MiB.');
        }
        $records = match ($extension) {
            'csv' => $this->csv($file->getRealPath()),
            'xlsx' => $this->xlsx($file->getRealPath()),
            default => $this->fail('Choose a CSV or XLSX file.'),
        };
        $headers = null;
        $rows = [];
        foreach ($records as $number => $values) {
            $values = array_map($this->cell(...), $values);
            if (count(array_filter($values, fn ($value) => $value !== '')) === 0) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn ($value) => strtolower(trim(str_replace("\u{FEFF}", '', $value))), $values);
                while ($headers !== [] && end($headers) === '') {
                    array_pop($headers);
                }
                $this->assertHeaders($headers);

                continue;
            }
            $cells = [];
            foreach ($headers as $i => $header) {
                $cells[$header] = $values[$i] ?? '';
            }
            $rows[] = ['row' => $number, 'cells' => $cells];
            if (count($rows) > self::MAX_ROWS) {
                $this->fail('Import at most '.self::MAX_ROWS.' rows per file. Split the sheet and import each part.');
            }
        }
        if ($headers === null || $rows === []) {
            $this->fail('The sheet has no product rows under its header row.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function assertHeaders(array $headers): void
    {
        if (count($headers) !== count(array_unique($headers))) {
            $this->fail('Each column header must be unique.');
        }
        $unknown = array_filter($headers, fn ($header) => ! in_array($header, self::PRODUCT_HEADERS, true) && ! in_array($header, self::VARIANT_HEADERS, true)
            && ! preg_match('/^option([1-9]|10)_(name|value)$/', $header));
        if ($unknown !== []) {
            $this->fail('Unknown columns: '.implode(', ', array_map(fn ($header) => $header === '' ? '(empty)' : $header, $unknown)).'. Download an export to see the supported columns.');
        }
    }

    /** @return \Generator<int, list<mixed>> */
    private function csv(string $path): \Generator
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            $this->fail('The CSV file could not be read.');
        }
        try {
            $first = (string) fgets($stream);
            rewind($stream);
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            $number = 0;
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $number++;
                if (! mb_check_encoding(implode('', array_map('strval', $values)), 'UTF-8')) {
                    $this->fail('Save the CSV file with UTF-8 encoding.');
                }
                yield $number => array_map(fn ($value) => is_string($value) ? self::unescapeCsv($value) : $value, $values);
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return \Generator<int, list<mixed>> */
    private function xlsx(string $path): \Generator
    {
        if ($problem = XlsxArchiveGuard::problem($path)) {
            $this->fail($problem);
        }
        $reader = new Reader;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    yield $number => array_map(fn ($cell) => $cell->getValue(), $row->getCells());
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
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim(str_replace("\0", '', (string) $value));
    }

    // ---------------------------------------------------------------- planning

    /**
     * @param  array{headers: list<string>, rows: list<array{row: int, cells: array<string, string>}>}  $sheet
     * @param  callable(array): void  $validateCreate  throws ValidationException for an invalid create plan
     */
    public function plan(array $sheet, string $mode, User $actor, callable $validateCreate, bool $lock = false): array
    {
        $headers = array_flip($sheet['headers']);
        $report = [];
        foreach ($sheet['rows'] as $row) {
            $report[$row['row']] = ['row' => $row['row'], 'action' => $mode === 'create' ? 'create' : 'unchanged', 'product' => $row['cells']['name'] ?? null,
                'variant' => null, 'changes' => [], 'errors' => []];
        }
        $result = $mode === 'create' ? $this->planCreate($sheet, $headers, $report, $actor, $validateCreate) : $this->planUpdate($sheet, $headers, $report, $actor, $lock);
        foreach ($report as &$entry) {
            if ($entry['errors'] !== []) {
                $entry['action'] = 'error';
            }
        }
        unset($entry);
        $rows = array_values($report);
        $summary = ['rows' => count($rows), 'errors' => count(array_filter($rows, fn ($row) => $row['action'] === 'error')),
            'unchanged' => count(array_filter($rows, fn ($row) => $row['action'] === 'unchanged')),
            'create_products' => count($result['creates'] ?? []), 'create_variants' => array_sum(array_map(fn ($plan) => count($plan['variants']), $result['creates'] ?? [])),
            'update_products' => count(array_filter($result['updates'] ?? [], fn ($update) => $update['product_changes'] !== [] || $update['categories'] !== null || $update['stock'] !== null)),
            'update_variants' => array_sum(array_map(fn ($update) => count(array_filter($update['variants'], fn ($variant) => $variant['changes'] !== [] || $variant['stock'] !== null)), $result['updates'] ?? []))];

        return ['summary' => $summary, 'rows' => $rows, 'creates' => $result['creates'] ?? [], 'updates' => $result['updates'] ?? []];
    }

    private function planCreate(array $sheet, array $headers, array &$report, User $actor, callable $validateCreate): array
    {
        if (! isset($headers['name']) || ! isset($headers['price'])) {
            $this->fail('Creating products needs the name and price columns.');
        }
        $groups = [];
        foreach ($sheet['rows'] as $row) {
            $cells = $row['cells'];
            $key = ($cells['product_id'] ?? '') !== '' ? 'id:'.$cells['product_id'] : (($cells['slug'] ?? '') !== '' ? 'slug:'.mb_strtolower($cells['slug']) : 'name:'.mb_strtolower($cells['name'] ?? ''));
            $groups[$key][] = $row;
        }
        $creates = [];
        $skus = $variantSkus = [];
        $variantTotal = 0;
        foreach ($groups as $rows) {
            $first = $rows[0];
            $errors = [];
            $product = $this->productFields($first['cells'], $headers, $errors);
            foreach (array_slice($rows, 1) as $row) {
                $this->conflicts($product, $this->productFields($row['cells'], $headers, $report[$row['row']]['errors']), $first['row'], $report[$row['row']]['errors']);
            }
            if (($product['name'] ?? '') === '') {
                $errors[] = $this->error('required', 'name', 'Enter the product name.');
            }
            if (! isset($product['price'])) {
                $errors[] = $this->error('required', 'price', 'Enter the product price.');
            }
            $optioned = array_filter($rows, fn ($row) => $this->options($row['cells']) !== []);
            $plan = ['data' => ['name' => $product['name'] ?? '', 'price' => $product['price'] ?? '0', 'compare_price' => $product['compare_price'] ?? null,
                'is_active' => $product['is_active'] ?? true, 'personalization_fields' => []],
                'currency' => null, 'categories' => array_map(fn ($category) => ['id' => $category->id, 'name' => $category->name], $product['categories'] ?? []), 'images' => [],
                'axes' => [], 'variants' => [], 'stock_quantity' => 0, 'track_inventory' => false, 'warnings' => []];
            if (($product['sku'] ?? null) !== null) {
                $plan['data']['sku'] = $product['sku'];
                if ($duplicate = $this->fileSku($product['sku'], $skus, $first['row'], 'sku')) {
                    $errors[] = $duplicate;
                }
            }
            if (($first['cells']['slug'] ?? '') !== '' && preg_match('/^[a-z0-9\-]+$/', $first['cells']['slug'])) {
                $plan['data']['slug'] = $first['cells']['slug'];
            }
            if ($optioned === []) {
                if (count($rows) > 1) {
                    foreach (array_slice($rows, 1) as $row) {
                        $report[$row['row']]['errors'][] = $this->error('duplicate_row', 'name', 'Another row already creates this product. Add option values to create options, or give the product a different name.');
                    }
                }
                $stock = $this->stock($first['cells'], $headers, $report[$first['row']]['errors']);
                $plan['stock_quantity'] = $stock['stock'] ?? 0;
                $plan['track_inventory'] = $stock['track'] ?? ($stock['stock'] !== null);
            } else {
                $axes = [];
                $names = null;
                foreach ($rows as $row) {
                    $options = $this->options($row['cells']);
                    $rowErrors = &$report[$row['row']]['errors'];
                    if ($options === []) {
                        $rowErrors[] = $this->error('options_mismatch', 'option1_value', 'Every row of a product with options needs its option values.');

                        continue;
                    }
                    $keys = array_map(fn ($name) => ProductOptionDisplay::key($name), array_keys($options));
                    if ($names !== null && $keys !== $names) {
                        $rowErrors[] = $this->error('options_mismatch', 'option1_name', 'Use the same option names in the same order on every row of a product.');

                        continue;
                    }
                    $names ??= $keys;
                    foreach ($options as $name => $value) {
                        $axes[$name] ??= [];
                        $axes[$name][ProductOptionDisplay::key($value)] ??= ['value' => $value];
                    }
                    $variant = $this->variantFields($row['cells'], $headers, $rowErrors);
                    $stock = $this->stock($row['cells'], $headers, $rowErrors);
                    if (($variant['sku'] ?? null) !== null) {
                        if ($duplicate = $this->fileSku($variant['sku'], $variantSkus, $row['row'], 'variant_sku')) {
                            $rowErrors[] = $duplicate;
                        }
                    }
                    $plan['variants'][] = ['name' => implode(' / ', $options), 'options' => $options, 'sku' => $variant['sku'] ?? null,
                        'price_override' => $variant['price_override'] ?? null, 'compare_price' => $variant['compare_price'] ?? null,
                        'is_active' => $variant['is_active'] ?? true, 'stock_quantity' => $stock['stock'] ?? 0, 'track_inventory' => $stock['track'] ?? ($stock['stock'] !== null)];
                    $report[$row['row']]['variant'] = implode(' / ', $options);
                    unset($rowErrors);
                }
                $plan['axes'] = array_map(fn ($name) => ['name' => $name, 'type' => 'buttons', 'values' => array_values($axes[$name])], array_keys($axes));
                $variantTotal += count($plan['variants']);
            }
            $report[$first['row']]['errors'] = array_merge($report[$first['row']]['errors'], $errors);
            if (array_filter(array_map(fn ($row) => $report[$row['row']]['errors'], $rows)) === []) {
                try {
                    $validateCreate($plan);
                } catch (ValidationException $error) {
                    foreach ($error->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $report[$first['row']]['errors'][] = $this->error(str_contains($field, 'sku') && str_contains($message, 'taken') ? 'sku_taken' : 'invalid', (string) preg_replace('/^products\.\d+\./', '', $field), $message);
                        }
                    }
                }
            }
            $creates[] = $plan + ['rows' => array_column($rows, 'row')];
        }
        if ($variantTotal > 2000) {
            $this->fail('Import at most 2000 options per file.');
        }

        return ['creates' => $creates];
    }

    private function planUpdate(array $sheet, array $headers, array &$report, User $actor, bool $lock): array
    {
        if (! array_intersect_key($headers, array_flip(['product_id', 'variant_id', 'sku', 'variant_sku', 'slug']))) {
            $this->fail('Updating products needs a product_id, variant_id, sku, variant_sku or slug column to match each row.');
        }
        // Resolve identities first so locks are taken in id order.
        $cells = array_column($sheet['rows'], 'cells');
        $productIds = array_filter(array_map(fn ($row) => ctype_digit($row['product_id'] ?? '') ? (int) $row['product_id'] : null, $cells));
        $variantIds = array_filter(array_map(fn ($row) => ctype_digit($row['variant_id'] ?? '') ? (int) $row['variant_id'] : null, $cells));
        $productSkus = array_values(array_filter(array_column($cells, 'sku')));
        $variantSkus = array_values(array_filter(array_column($cells, 'variant_sku')));
        $slugs = array_values(array_filter(array_column($cells, 'slug')));
        $variantMatches = ProductVariant::query()->where(fn ($q) => $q->whereIn('id', $variantIds ?: [0])->orWhereIn('sku', $variantSkus ?: ['']))->get(['id', 'store_product_id', 'sku']);
        $ids = Product::query()->where(fn ($q) => $q->whereIn('id', array_merge($productIds, $variantMatches->pluck('store_product_id')->all()) ?: [0])
            ->orWhereIn('sku', $productSkus ?: [''])->orWhereIn('slug', $slugs ?: ['']))->pluck('id')->all();
        /** @var Collection<int, Product> $products */
        $products = Product::query()->whereIn('id', $ids ?: [0])->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $variants = ProductVariant::query()->whereIn('store_product_id', $products->keys()->all() ?: [0])->orderBy('store_product_id')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $variantsById = $variants->keyBy('id');
        $byProduct = $variants->groupBy('store_product_id');
        $updates = [];
        $seen = [];
        $fileSkus = $fileVariantSkus = [];
        foreach ($sheet['rows'] as $row) {
            $c = $row['cells'];
            $errors = &$report[$row['row']]['errors'];
            $product = $variant = null;
            if (($c['product_id'] ?? '') !== '') {
                $product = ctype_digit($c['product_id']) ? $products->get((int) $c['product_id']) : null;
                if (! $product) {
                    $errors[] = $this->error('product_not_found', 'product_id', 'No product with this ID exists in this store.');
                }
            } elseif (($c['variant_id'] ?? '') !== '' || ($c['variant_sku'] ?? '') !== '') {
                $match = ($c['variant_id'] ?? '') !== '' ? (ctype_digit($c['variant_id']) ? $variantsById->get((int) $c['variant_id']) : null)
                    : $variants->first(fn ($item) => $item->sku !== null && mb_strtolower($item->sku) === mb_strtolower($c['variant_sku']));
                $product = $match ? $products->get($match->store_product_id) : null;
                if (! $product) {
                    $errors[] = $this->error('variant_not_found', ($c['variant_id'] ?? '') !== '' ? 'variant_id' : 'variant_sku', 'No option with this ID or SKU exists in this store.');
                }
            } elseif (($c['sku'] ?? '') !== '') {
                $product = $products->first(fn ($item) => $item->sku !== null && mb_strtolower($item->sku) === mb_strtolower($c['sku']));
                if (! $product) {
                    $errors[] = $this->error('product_not_found', 'sku', 'No product with this SKU exists in this store.');
                }
            } elseif (($c['slug'] ?? '') !== '') {
                $product = $products->first(fn ($item) => $item->slug === $c['slug']);
                if (! $product) {
                    $errors[] = $this->error('product_not_found', 'slug', 'No product with this slug exists in this store.');
                }
            } else {
                $errors[] = $this->error('product_not_found', 'product_id', 'Add product_id, sku or variant_sku to match an existing product.');
            }
            if (! $product) {
                unset($errors);

                continue;
            }
            $report[$row['row']]['product'] = $product->name;
            $own = $byProduct->get($product->id, collect());
            if ($own->isNotEmpty()) {
                if (($c['variant_id'] ?? '') !== '') {
                    $variant = ctype_digit($c['variant_id']) ? $own->firstWhere('id', (int) $c['variant_id']) : null;
                } elseif (($c['variant_sku'] ?? '') !== '' && $own->contains(fn ($item) => $item->sku !== null && mb_strtolower($item->sku) === mb_strtolower($c['variant_sku']))) {
                    $variant = $own->first(fn ($item) => $item->sku !== null && mb_strtolower($item->sku) === mb_strtolower($c['variant_sku']));
                } elseif (($options = $this->options($c)) !== []) {
                    $key = $this->optionsKey($options);
                    $variant = $own->first(fn ($item) => $this->optionsKey($item->options ?? []) === $key);
                }
                if (! $variant) {
                    $errors[] = $this->error('variant_not_found', 'variant_id', 'This product has options. Match the row to one of them with variant_id, variant_sku or its option values; new options are added in the product editor.');
                    unset($errors);

                    continue;
                }
                $report[$row['row']]['variant'] = $variant->name;
            } elseif (($c['variant_id'] ?? '') !== '' || $this->options($c) !== []) {
                $errors[] = $this->error('no_variants', 'variant_id', 'This product has no options. Clear the option columns for this row; new options are added in the product editor.');
                unset($errors);

                continue;
            }
            $identity = $product->id.':'.($variant?->id ?? 0);
            if (isset($seen[$identity])) {
                $errors[] = $this->error('duplicate_row', 'product_id', 'Row '.$seen[$identity].' already updates this '.($variant ? 'option' : 'product').'.');
                unset($errors);

                continue;
            }
            $seen[$identity] = $row['row'];
            $isFirst = ! isset($updates[$product->id]);
            $updates[$product->id] ??= ['product_id' => $product->id, 'rows' => [], 'product_changes' => [], 'categories' => null, 'stock' => null, 'variants' => [], 'first_row' => $row['row'], 'fields' => []];
            $update = &$updates[$product->id];
            $update['rows'][] = $row['row'];
            $fields = $this->productFields($c, $headers, $errors);
            if ($isFirst) {
                $update['fields'] = $fields;
                $this->productChanges($product, $update, $fields, $report[$row['row']], $fileSkus, $actor);
            } else {
                $this->conflicts($update['fields'], $fields, $update['first_row'], $errors);
            }
            $stock = $this->stock($c, $headers, $errors);
            $digital = in_array($product->digital_type, ['codes', 'link'], true);
            $target = $variant ?? $product;
            $stockChange = null;
            if (($stock['stock'] !== null && $stock['stock'] !== $target->stock_quantity) || ($stock['track'] !== null && $stock['track'] !== $target->track_inventory)) {
                if ($digital) {
                    $errors[] = $this->error('stock_digital', 'stock', 'Digital product stock is managed by its delivery settings, not by the sheet.');
                } else {
                    $quantity = $stock['stock'] ?? $target->stock_quantity;
                    $tracking = $stock['track'] ?? $target->track_inventory;
                    if ($quantity < $target->reserved_quantity || (! $tracking && $target->reserved_quantity > 0)) {
                        $errors[] = $this->error('stock_reserved', 'stock', 'Keep at least '.$target->reserved_quantity.' units and tracking on for reserved orders.');
                    } else {
                        $stockChange = ['stock_quantity' => $quantity, 'track_inventory' => $tracking];
                        if ($quantity !== $target->stock_quantity) {
                            $report[$row['row']]['changes'][] = ['field' => 'stock', 'from' => $target->stock_quantity, 'to' => $quantity];
                        }
                        if ($tracking !== $target->track_inventory) {
                            $report[$row['row']]['changes'][] = ['field' => 'track_inventory', 'from' => $target->track_inventory, 'to' => $tracking];
                        }
                    }
                }
            }
            if ($variant) {
                $changes = [];
                foreach ($this->variantFields($c, $headers, $errors) as $field => $value) {
                    if ($field === 'sku' && $value !== null && ($duplicate = $this->fileSku($value, $fileVariantSkus, $row['row'], 'variant_sku'))) {
                        $errors[] = $duplicate;
                    }
                    $current = $variant->{$field};
                    $same = in_array($field, ['price_override', 'compare_price'], true)
                        ? ($current === null ? $value === null : $value !== null && bccomp((string) $current, (string) $value, 2) === 0)
                        : ($field === 'sku' ? (string) $current === (string) $value : $current === $value);
                    if (! $same) {
                        if ($field === 'sku' && $value !== null) {
                            if (ProductVariant::query()->where('sku', $value)->whereKeyNot($variant->id)->exists()) {
                                $errors[] = $this->error('sku_taken', 'variant_sku', 'Another option already uses this SKU.');
                            }
                        }
                        $changes[$field] = $value;
                        $report[$row['row']]['changes'][] = ['field' => 'variant_'.($field === 'price_override' ? 'price' : ($field === 'is_active' ? 'status' : $field)), 'from' => $current, 'to' => $value];
                    }
                }
                $update['variants'][$variant->id] = ['variant_id' => $variant->id, 'changes' => $changes, 'stock' => $stockChange];
            } else {
                $update['stock'] = $stockChange;
            }
            if ($report[$row['row']]['changes'] !== []) {
                $report[$row['row']]['action'] = 'update';
            }
            unset($update, $errors);
        }

        return ['updates' => array_values($updates)];
    }

    private function productChanges(Product $product, array &$update, array $fields, array &$entry, array &$fileSkus, User $actor): void
    {
        foreach ($fields as $field => $value) {
            if ($field === 'categories') {
                $current = collect([$product->category_id])->merge($product->additionalCategories()->pluck('categories.id'))->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
                $next = array_map(fn ($category) => (int) $category->id, $value ?? []);
                if ($current !== $next) {
                    $names = Category::query()->whereIn('id', array_merge($current, $next) ?: [0])->pluck('name', 'id');
                    $update['categories'] = $next;
                    $entry['changes'][] = ['field' => 'categories', 'from' => implode(' | ', array_map(fn ($id) => $names[$id] ?? '#'.$id, $current)), 'to' => implode(' | ', array_map(fn ($id) => $names[$id] ?? '#'.$id, $next))];
                }

                continue;
            }
            if ($field === 'sku' && $value !== null && ($duplicate = $this->fileSku($value, $fileSkus, $entry['row'], 'sku'))) {
                $entry['errors'][] = $duplicate;
            }
            $current = $product->{$field};
            $same = in_array($field, ['price', 'compare_price'], true)
                ? ($current === null ? $value === null : $value !== null && bccomp((string) $current, (string) $value, 2) === 0)
                : ($field === 'sku' ? (string) $current === (string) $value : $current === $value);
            if ($same) {
                continue;
            }
            if ($field === 'sku' && $value !== null) {
                if (Product::query()->where('sku', $value)->whereKeyNot($product->id)->exists()) {
                    $entry['errors'][] = $this->error('sku_taken', 'sku', 'Another product already uses this SKU.');
                }
            }
            $update['product_changes'][$field] = $value;
            $entry['changes'][] = ['field' => $field === 'is_active' ? 'status' : $field, 'from' => $current, 'to' => $value];
        }
    }

    /** Later rows of a product may leave product cells empty; filled cells must repeat the first row. */
    private function conflicts(array $first, array $fields, int $firstRow, array &$errors): void
    {
        foreach ($fields as $field => $value) {
            if (array_key_exists($field, $first) && $this->sameValue($first[$field], $value)) {
                continue;
            }
            $errors[] = $this->error('product_conflict', $field === 'is_active' ? 'status' : $field, 'This product value differs from row '.$firstRow.'. Use the same product values on every row, or fill them on the first row only.');
        }
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return is_array($left) && is_array($right) && array_map(fn ($item) => $item->id ?? $item, $left) === array_map(fn ($item) => $item->id ?? $item, $right);
        }

        return $left === null || $right === null ? $left === $right : (string) $left === (string) $right;
    }

    private function cleared(string $value): bool
    {
        return mb_strtolower($value) === self::CLEAR;
    }

    /**
     * Product-level values present in the sheet. A blank cell keeps the current value (it is left out);
     * the clear token sets an optional value to empty and is refused for required values.
     */
    private function productFields(array $cells, array $headers, array &$errors): array
    {
        $fields = [];
        foreach (['name', 'status', 'price'] as $required) {
            if (isset($headers[$required]) && $this->cleared($cells[$required])) {
                $errors[] = $this->error('required', $required, 'This value cannot be cleared.');
                $cells[$required] = '';
            }
        }
        if (isset($headers['name']) && $cells['name'] !== '') {
            if (mb_strlen($cells['name']) > 255) {
                $errors[] = $this->error('too_long', 'name', 'Use at most 255 characters for the product name.');
            } else {
                $fields['name'] = $cells['name'];
            }
        }
        if (isset($headers['status']) && $cells['status'] !== '') {
            $status = $this->status($cells['status']);
            $status === null ? $errors[] = $this->error('invalid_status', 'status', 'Use active or draft.') : $fields['is_active'] = $status;
        }
        if (isset($headers['price']) && $cells['price'] !== '') {
            $price = $this->price($cells['price']);
            $price === null ? $errors[] = $this->error('invalid_price', 'price', 'Use a price between 0 and 999999999.99 with at most two decimals.') : $fields['price'] = $price;
        }
        if (isset($headers['compare_price']) && $cells['compare_price'] !== '') {
            $price = $this->cleared($cells['compare_price']) ? null : $this->price($cells['compare_price']);
            $price === null && ! $this->cleared($cells['compare_price'])
                ? $errors[] = $this->error('invalid_price', 'compare_price', 'Use a compare-at price between 0 and 999999999.99 with at most two decimals.')
                : $fields['compare_price'] = $price;
        }
        if (isset($headers['sku']) && $cells['sku'] !== '') {
            if (mb_strlen($cells['sku']) > 120) {
                $errors[] = $this->error('too_long', 'sku', 'Use at most 120 characters for the SKU.');
            } else {
                $fields['sku'] = $this->cleared($cells['sku']) ? null : $cells['sku'];
            }
        }
        if (isset($headers['category_ids']) && $cells['category_ids'] !== '') {
            if ($this->cleared($cells['category_ids'])) {
                $fields['categories'] = [];
            } elseif (! preg_match('/^\d+(\s*,\s*\d+)*$/', $cells['category_ids'])) {
                $errors[] = $this->error('invalid', 'category_ids', 'List category IDs separated by commas, for example 3,7.');
            } else {
                $ids = array_values(array_unique(array_map('intval', preg_split('/\s*,\s*/', $cells['category_ids']) ?: [])));
                $found = Category::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
                if (count($ids) > 20 || $found->count() !== count($ids)) {
                    $errors[] = $this->error('category_not_found', 'category_ids', 'Use up to 20 category IDs that exist in this store.');
                } else {
                    $fields['categories'] = array_map(fn ($id) => $found[$id], $ids);
                }
            }
        }

        return $fields;
    }

    private function variantFields(array $cells, array $headers, array &$errors): array
    {
        $fields = [];
        if (isset($headers['variant_sku']) && $cells['variant_sku'] !== '') {
            if (mb_strlen($cells['variant_sku']) > 120) {
                $errors[] = $this->error('too_long', 'variant_sku', 'Use at most 120 characters for the option SKU.');
            } else {
                $fields['sku'] = $this->cleared($cells['variant_sku']) ? null : $cells['variant_sku'];
            }
        }
        foreach (['variant_price' => 'price_override', 'variant_compare_price' => 'compare_price'] as $column => $field) {
            if (isset($headers[$column]) && $cells[$column] !== '') {
                $price = $this->cleared($cells[$column]) ? null : $this->price($cells[$column]);
                if ($price === null && ! $this->cleared($cells[$column])) {
                    $errors[] = $this->error('invalid_price', $column, 'Use a price between 0 and 999999999.99 with at most two decimals.');
                } else {
                    $fields[$field] = $price;
                }
            }
        }
        if (isset($headers['variant_status']) && $cells['variant_status'] !== '') {
            $status = $this->cleared($cells['variant_status']) ? null : $this->status($cells['variant_status']);
            $status === null ? $errors[] = $this->error('invalid_status', 'variant_status', 'Use active or draft.') : $fields['is_active'] = $status;
        }

        return $fields;
    }

    /** @return array{stock: ?int, track: ?bool} */
    private function stock(array $cells, array $headers, array &$errors): array
    {
        $stock = $track = null;
        if (isset($headers['stock']) && $cells['stock'] !== '') {
            if (! preg_match('/^\d{1,9}$/', $cells['stock']) || (int) $cells['stock'] > 100000000) {
                $errors[] = $this->error('invalid_number', 'stock', 'Use a whole stock quantity between 0 and 100000000.');
            } else {
                $stock = (int) $cells['stock'];
            }
        }
        if (isset($headers['track_inventory']) && $cells['track_inventory'] !== '') {
            $value = mb_strtolower($cells['track_inventory']);
            $track = in_array($value, ['1', 'true', 'yes', 'نعم'], true) ? true : (in_array($value, ['0', 'false', 'no', 'لا'], true) ? false : null);
            if ($track === null) {
                $errors[] = $this->error('invalid_boolean', 'track_inventory', 'Use 1 to track inventory or 0 to stop tracking.');
            }
        }

        return ['stock' => $stock, 'track' => $track];
    }

    /** @return array<string, string> option name => value, in column order */
    private function options(array $cells): array
    {
        $options = [];
        for ($i = 1; $i <= 10; $i++) {
            $name = $cells['option'.$i.'_name'] ?? '';
            $value = $cells['option'.$i.'_value'] ?? '';
            if ($name !== '' && $value !== '') {
                $options[mb_substr($name, 0, 80)] = mb_substr($value, 0, 120);
            }
        }

        return $options;
    }

    private function optionsKey(array $options): string
    {
        $normalized = [];
        foreach ($options as $name => $value) {
            $normalized[ProductOptionDisplay::key((string) $name)] = ProductOptionDisplay::key((string) $value);
        }
        ksort($normalized);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    private function price(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', $value);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value) || (float) $value > self::MAX_PRICE) {
            return null;
        }

        return bcadd($value, '0', 2);
    }

    private function status(string $value): ?bool
    {
        $value = mb_strtolower($value);

        return in_array($value, ['active', '1', 'true', 'published', 'نشط', 'منشور'], true) ? true
            : (in_array($value, ['draft', '0', 'false', 'hidden', 'مسودة', 'مخفي'], true) ? false : null);
    }

    /** @return array<string, string>|null an error when the SKU already appeared earlier in the file */
    private function fileSku(string $sku, array &$known, int $row, string $field): ?array
    {
        $key = mb_strtolower($sku);
        if (isset($known[$key])) {
            return $this->error('sku_duplicate', $field, 'Row '.$known[$key].' already uses this SKU in the file.');
        }
        $known[$key] = $row;

        return null;
    }

    private function error(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    // ---------------------------------------------------------------- apply (caller holds the transaction and row locks)

    public function applyUpdates(array $updates, User $actor): int
    {
        $count = 0;
        foreach ($updates as $update) {
            $variantChanges = array_filter($update['variants'], fn ($item) => $item['changes'] !== [] || $item['stock'] !== null);
            if ($update['product_changes'] === [] && $update['categories'] === null && $update['stock'] === null && $variantChanges === []) {
                continue;
            }
            $product = Product::query()->whereKey($update['product_id'])->firstOrFail();
            if ($update['product_changes'] !== []) {
                $product->fill($update['product_changes']);
            }
            if ($update['categories'] !== null) {
                $ids = Category::query()->whereIn('id', $update['categories'] ?: [0])->pluck('id')->all();
                $ids = array_values(array_filter($update['categories'], fn ($id) => in_array($id, $ids, false)));
                $product->category_id = $ids[0] ?? null;
                $product->additionalCategories()->sync(array_fill_keys($ids, ['store_id' => $product->store_id]));
            }
            $product->save();
            if ($update['stock'] !== null) {
                $this->adjustStock($product, $update['stock'], $actor);
            }
            foreach ($variantChanges as $item) {
                $variant = ProductVariant::query()->where('store_product_id', $product->id)->whereKey($item['variant_id'])->firstOrFail();
                if ($item['changes'] !== []) {
                    $variant->fill($item['changes'])->save();
                }
                if ($item['stock'] !== null) {
                    $this->adjustStock($variant, $item['stock'], $actor);
                }
            }
            $count++;
        }

        return $count;
    }

    private function adjustStock(Product|ProductVariant $model, array $stock, User $actor): void
    {
        $model->refresh();
        if ($model->stock_quantity === $stock['stock_quantity'] && $model->track_inventory === $stock['track_inventory']) {
            return;
        }
        app(StoreInventory::class)->adjust($model, $stock + ['expected_stock' => $model->stock_quantity, 'expected_reserved' => $model->reserved_quantity,
            'expected_tracking' => $model->track_inventory, 'note' => 'Catalog sheet import'], $actor->id, false);
    }
}
