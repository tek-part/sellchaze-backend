<?php

namespace App\Support\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Smart-collection conditions (Shopify "automated collections"): `{field, operator, value}` rows
 * combined with `match: all|any`. Rules compile to one SQL query so storefront pagination and
 * counts never load products into PHP.
 */
final class CollectionRules
{
    /** field => allowed operators */
    public const FIELDS = [
        'title' => ['contains', 'not_contains', 'equals', 'starts_with'],
        'price' => ['gt', 'lt', 'eq'],
        'category' => ['is', 'is_not'],
        'brand' => ['is', 'is_not'],
        'tag' => ['is', 'is_not'],
        'inventory' => ['is'],
        'created_at' => ['within_days'],
    ];

    public const MATCHES = ['all', 'any'];

    public const MAX_RULES = 20;

    /** Inventory values reuse the products list's stock semantics (variants, reservations, untracked). */
    public const INVENTORY_VALUES = ['in_stock', 'out_of_stock'];

    /** @return array<string, array<int, mixed>> */
    public static function validationRules(bool $required = false): array
    {
        return [
            'rules' => [$required ? 'required' : 'sometimes', 'nullable', 'array', 'max:'.self::MAX_RULES],
            'rules.*' => ['required', 'array:field,operator,value'],
            'rules.*.field' => ['required', 'string', 'in:'.implode(',', array_keys(self::FIELDS))],
            'rules.*.operator' => ['required', 'string'],
            'rules.*.value' => ['present'],
            'match' => ['sometimes', 'string', 'in:'.implode(',', self::MATCHES)],
        ];
    }

    /** Multipart forms send `rules` as a JSON string; decode it before validation. */
    public static function decodeRequest(Request $request): void
    {
        $raw = $request->input('rules');
        if (is_string($raw)) {
            $decoded = json_decode(trim($raw) === '' ? 'null' : $raw, true);
            $request->merge(['rules' => is_array($decoded) ? $decoded : ($decoded === null ? null : $raw)]);
        }
    }

    /** Operator/value checks that depend on the field (run in a validator `after` hook). */
    public static function validateRows(Validator $validator, mixed $rules): void
    {
        if (! is_array($rules)) {
            return;
        }
        foreach (array_values($rules) as $index => $rule) {
            if (! is_array($rule) || ! isset(self::FIELDS[$rule['field'] ?? ''])) {
                continue;
            }
            $field = (string) $rule['field'];
            $operator = $rule['operator'] ?? null;
            $value = $rule['value'] ?? null;
            if (! in_array($operator, self::FIELDS[$field], true)) {
                $validator->errors()->add("rules.{$index}.operator", 'Condition '.($index + 1).": choose a valid operator for {$field}.");

                continue;
            }
            $error = match ($field) {
                'title' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255 ? null : 'enter the text to match (up to 255 characters).',
                'price' => is_numeric($value) && (float) $value >= 0 ? null : 'enter a price of 0 or more.',
                'category' => self::positiveInt($value) && Category::query()->whereKey((int) $value)->exists() ? null : 'choose a category from this store.',
                'brand' => self::positiveInt($value) && StoreBrand::query()->whereKey((int) $value)->exists() ? null : 'choose a brand from this store.',
                'tag' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 100 ? null : 'enter a tag (up to 100 characters).',
                'inventory' => in_array($value, self::INVENTORY_VALUES, true) ? null : 'choose in stock or out of stock.',
                'created_at' => self::positiveInt($value) && (int) $value <= 3650 ? null : 'enter a number of days between 1 and 3650.',
                default => null,
            };
            if ($error !== null) {
                $validator->errors()->add("rules.{$index}.value", 'Condition '.($index + 1).': '.$error);
            }
        }
    }

    /**
     * Normalise validated rows to the stored shape (typed values, trimmed text).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return list<array{field: string, operator: string, value: string|int|float}>
     */
    public static function normalize(array $rules): array
    {
        return array_values(array_map(fn (array $rule) => [
            'field' => (string) $rule['field'],
            'operator' => (string) $rule['operator'],
            'value' => match ($rule['field']) {
                'price' => round((float) $rule['value'], 2),
                'category', 'brand', 'created_at' => (int) $rule['value'],
                default => trim((string) $rule['value']),
            },
        ], $rules));
    }

    /**
     * Constrain a products query to the rows matching the rules.
     *
     * @param  Builder<Product>  $query
     * @param  array<int, array<string, mixed>>  $rules
     */
    public static function apply(Builder $query, array $rules, string $match): void
    {
        $query->where(function (Builder $group) use ($rules, $match) {
            foreach ($rules as $rule) {
                $clause = fn (Builder $q) => self::applyRule($q, $rule);
                $match === 'any' ? $group->orWhere($clause) : $group->where($clause);
            }
        });
    }

    /**
     * @param  Builder<Product>  $q
     * @param  array<string, mixed>  $rule
     */
    private static function applyRule(Builder $q, array $rule): void
    {
        $value = $rule['value'] ?? null;
        $operator = (string) ($rule['operator'] ?? '');
        switch ($rule['field'] ?? null) {
            case 'title':
                $text = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $value);
                match ($operator) {
                    'contains' => $q->where('products.name', 'like', "%{$text}%"),
                    'not_contains' => $q->where('products.name', 'not like', "%{$text}%"),
                    'starts_with' => $q->where('products.name', 'like', "{$text}%"),
                    default => $q->where('products.name', (string) $value),
                };
                break;
            case 'price':
                $q->where('products.price', ['gt' => '>', 'lt' => '<'][$operator] ?? '=', (float) $value);
                break;
            case 'category':
                $id = (int) $value;
                $inPivot = fn ($pivot) => $pivot->selectRaw('1')->from('store_product_categories')
                    ->whereColumn('store_product_categories.product_id', 'products.id')
                    ->whereColumn('store_product_categories.store_id', 'products.store_id')
                    ->where('store_product_categories.category_id', $id);
                $operator === 'is_not'
                    ? $q->where(fn ($w) => $w->whereNull('products.category_id')->orWhere('products.category_id', '!=', $id))->whereNotExists($inPivot)
                    : $q->inCategories([$id]);
                break;
            case 'brand':
                $operator === 'is_not'
                    ? $q->where(fn ($w) => $w->whereNull('products.store_brand_id')->orWhere('products.store_brand_id', '!=', (int) $value))
                    : $q->where('products.store_brand_id', (int) $value);
                break;
            case 'tag':
                $operator === 'is_not'
                    ? $q->where(fn ($w) => $w->whereNull('products.tags')->orWhereJsonDoesntContain('products.tags', (string) $value))
                    : $q->whereJsonContains('products.tags', (string) $value);
                break;
            case 'inventory':
                ProductStockFilter::apply($q, (string) $value);
                break;
            case 'created_at':
                $q->where('products.created_at', '>=', now()->subDays((int) $value));
                break;
        }
    }

    private static function positiveInt(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }
}
