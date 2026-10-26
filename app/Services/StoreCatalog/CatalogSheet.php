<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\StoreInventory;
use App\Services\Commerce\StoreOrderExports;
use App\Support\ProductOptionDisplay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * Flat product sheets (XLSX/CSV): one row per option with its option values, SKU, prices and stock.
 * Exports round-trip into an "update existing" import (matched by id or SKU) or a "create new" import.
 * Planning never writes; it returns a per-row report so merchants can fix errors before applying.
 */
class CatalogSheet
{
    public const PRODUCT_HEADERS = ['product_id', 'variant_id', 'slug', 'name', 'status', 'categories', 'price', 'compare_price', 'sku', 'stock', 'track_inventory'];

    public const VARIANT_HEADERS = ['variant_sku', 'variant_price', 'variant_compare_price', 'variant_status'];

    public const MAX_ROWS = 2000;

    public const MAX_EXPORT_ROWS = 10000;

    private const MAX_PRICE = 999999999.99;

    // ---------------------------------------------------------------- export

    /** @return array{0: string, 1: string} temporary file path and download name */
    public function export(?array $productIds, ?int $categoryId, string $format): array
    {
        $products = Product::query()->with(['variants', 'category', 'additionalCategories'])
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))
            ->when($categoryId !== null, fn ($q) => $q->inCategories([$categoryId]))
            ->orderBy('id')->get();
        if ($productIds !== null && $products->count() !== count(array_unique($productIds))) {
            throw ValidationException::withMessages(['products' => 'Refresh the list: a selected product is unavailable.']);
        }
        if ($products->sum(fn ($product) => max(1, $product->variants->count())) > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['products' => 'Sheet exports support at most '.self::MAX_EXPORT_ROWS.' rows. Export one category or a smaller selection.']);
        }
        $axes = max(3, min(10, (int) $products->flatMap(fn ($product) => $product->variants->map(fn ($variant) => count($variant->options ?? [])))->max()));
        $headers = $this->headers($axes);
        $path = tempnam(sys_get_temp_dir(), 'catalog-sheet-');
        try {
            if ($format === 'csv') {
                $stream = fopen($path, 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $headers, ',', '"', '');
                foreach ($products as $product) {
                    foreach ($this->rows($product, $axes) as $row) {
                        fputcsv($stream, array_map(fn ($value) => is_string($value) ? StoreOrderExports::cell($value) : (string) ($value ?? ''), $row), ',', '"', '');
                    }
                }
                fclose($stream);
            } else {
                $writer = new Writer;
                $writer->openToFile($path);
                $writer->addRow(Row::fromValues($headers));
                foreach ($products as $product) {
                    foreach ($this->rows($product, $axes) as $row) {
                        $writer->addRow(Row::fromValues($row));
                    }
                }
                $writer->close();
            }
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return [$path, 'sellchaze-products-'.now()->format('Y-m-d').'.'.$format];
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
        $categories = collect([$product->category])->merge($product->additionalCategories)->filter()->unique('id')->pluck('name')->implode(', ');
        $base = [$product->id, null, $product->slug, $product->name, $product->is_active ? 'active' : 'draft', $categories,
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
                yield $number => $values;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return \Generator<int, list<mixed>> */
    private function xlsx(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('The XLSX archive is invalid.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 1000) {
                $this->fail('The workbook contains too many archive entries.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $size += $entry === false ? PHP_INT_MAX : $entry['size'];
                if ($size > 40 * 1024 * 1024) {
                    $this->fail('The expanded workbook is larger than 40 MiB.');
                }
            }
        } finally {
            $zip->close();
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
        $text = trim(str_replace("\0", '', (string) $value));

        // Undo the spreadsheet-formula guard applied by our CSV exports.
        return preg_match("/^'[\\s]*[=+\\-@]/u", $text) ? substr($text, 1) : $text;
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
            $product = $this->productFields($first['cells'], $headers, $errors, false);
            $this->categoryPermission($product['categories'] ?? [], $actor, $errors);
            foreach (array_slice($rows, 1) as $row) {
                $this->conflicts($product, $this->productFields($row['cells'], $headers, $report[$row['row']]['errors'], false), $first['row'], $report[$row['row']]['errors']);
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
                'currency' => null, 'categories' => array_map(fn ($name) => ['name' => $name], $product['categories'] ?? []), 'images' => [],
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
            $fields = $this->productFields($c, $headers, $errors, $isFirst);
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
                $current = collect([$product->category_id])->merge($product->additionalCategories()->pluck('categories.id'))->filter()->unique()->values();
                $names = Category::query()->whereIn('id', $current->all() ?: [0])->pluck('name', 'id');
                $currentNames = $current->map(fn ($id) => $names[$id] ?? '')->all();
                if (array_map('mb_strtolower', $currentNames) !== array_map('mb_strtolower', $value)) {
                    $this->categoryPermission($value, $actor, $entry['errors']);
                    $update['categories'] = $value;
                    $entry['changes'][] = ['field' => 'categories', 'from' => implode(', ', $currentNames), 'to' => implode(', ', $value)];
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
            if ($value === null || (array_key_exists($field, $first) && $first[$field] == $value)) {
                continue;
            }
            $errors[] = $this->error('product_conflict', $field === 'is_active' ? 'status' : $field, 'This product value differs from row '.$firstRow.'. Use the same product values on every row, or fill them on the first row only.');
        }
    }

    /** Product-level values present in the sheet. */
    private function productFields(array $cells, array $headers, array &$errors, bool $requireName): array
    {
        $fields = [];
        if (isset($headers['name']) && $cells['name'] !== '') {
            if (mb_strlen($cells['name']) > 255) {
                $errors[] = $this->error('too_long', 'name', 'Use at most 255 characters for the product name.');
            } else {
                $fields['name'] = $cells['name'];
            }
        } elseif (isset($headers['name']) && $requireName) {
            $errors[] = $this->error('required', 'name', 'The product name cannot be empty.');
        }
        if (isset($headers['status']) && $cells['status'] !== '') {
            $status = $this->status($cells['status']);
            $status === null ? $errors[] = $this->error('invalid_status', 'status', 'Use active or draft.') : $fields['is_active'] = $status;
        }
        if (isset($headers['price']) && $cells['price'] !== '') {
            $price = $this->price($cells['price']);
            $price === null ? $errors[] = $this->error('invalid_price', 'price', 'Use a price between 0 and 999999999.99 with at most two decimals.') : $fields['price'] = $price;
        }
        if (isset($headers['compare_price'])) {
            if ($cells['compare_price'] === '') {
                $fields['compare_price'] = null;
            } else {
                $price = $this->price($cells['compare_price']);
                $price === null ? $errors[] = $this->error('invalid_price', 'compare_price', 'Use a compare-at price between 0 and 999999999.99 with at most two decimals.') : $fields['compare_price'] = $price;
            }
        }
        if (isset($headers['sku'])) {
            if (mb_strlen($cells['sku']) > 120) {
                $errors[] = $this->error('too_long', 'sku', 'Use at most 120 characters for the SKU.');
            } else {
                $fields['sku'] = $cells['sku'] === '' ? null : $cells['sku'];
            }
        }
        if (isset($headers['categories']) && $cells['categories'] !== '') {
            $names = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*[,،]\s*/u', $cells['categories']) ?: []), fn ($name) => $name !== '')));
            if (count($names) > 20 || array_filter($names, fn ($name) => mb_strlen($name) > 255)) {
                $errors[] = $this->error('invalid', 'categories', 'Use at most 20 category names of up to 255 characters.');
            } else {
                $fields['categories'] = $names;
            }
        }

        return $fields;
    }

    private function variantFields(array $cells, array $headers, array &$errors): array
    {
        $fields = [];
        if (isset($headers['variant_sku'])) {
            if (mb_strlen($cells['variant_sku']) > 120) {
                $errors[] = $this->error('too_long', 'variant_sku', 'Use at most 120 characters for the option SKU.');
            } else {
                $fields['sku'] = $cells['variant_sku'] === '' ? null : $cells['variant_sku'];
            }
        }
        foreach (['variant_price' => 'price_override', 'variant_compare_price' => 'compare_price'] as $column => $field) {
            if (isset($headers[$column])) {
                $price = $cells[$column] === '' ? null : $this->price($cells[$column]);
                if ($cells[$column] !== '' && $price === null) {
                    $errors[] = $this->error('invalid_price', $column, 'Use a price between 0 and 999999999.99 with at most two decimals.');
                } else {
                    $fields[$field] = $price;
                }
            }
        }
        if (isset($headers['variant_status']) && $cells['variant_status'] !== '') {
            $status = $this->status($cells['variant_status']);
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

    private function categoryPermission(array $names, User $actor, array &$errors): void
    {
        $existing = Category::query()->whereIn('name', $names)->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();
        if (array_diff(array_map('mb_strtolower', $names), $existing) !== [] && ! $actor->can('categories-create')) {
            $errors[] = $this->error('category_permission', 'categories', 'Creating new categories requires the categories-create permission.');
        }
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
                $ids = [];
                foreach ($update['categories'] as $name) {
                    $ids[] = (Category::query()->where('name', $name)->first() ?? app(StorefrontCategoryService::class)->create(['name' => $name, 'is_active' => true]))->id;
                }
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
