<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductPersonalizationUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductPersonalization
{
    public static function rules(): array
    {
        return [
            'personalization_fields' => ['sometimes', 'array', 'list', 'max:10'],
            'personalization_fields.*' => ['array:key,type,label,labels,required,max_length'],
            'personalization_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,79}$/', 'distinct'],
            'personalization_fields.*.type' => ['required', 'in:text,image'],
            'personalization_fields.*.label' => ['required', 'string', 'max:120'],
            'personalization_fields.*.labels' => ['sometimes', 'array:ar,en'],
            'personalization_fields.*.labels.*' => ['nullable', 'string', 'max:120'],
            'personalization_fields.*.required' => ['required', 'boolean'],
            'personalization_fields.*.max_length' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public static function inputRules(string $prefix = 'personalization'): array
    {
        return [$prefix => ['sometimes', 'array', 'max:10'], $prefix.'.*' => ['nullable', 'string', 'max:1000']];
    }

    public static function fields(Product $product): array
    {
        return array_map(fn ($field) => ['key' => $field['key'], 'type' => $field['type'],
            'label' => $field['labels'][app()->getLocale()] ?? $field['label'], 'required' => (bool) $field['required'], 'max_length' => (int) $field['max_length']], $product->personalization_fields ?? []);
    }

    /** Validate against live schema; order snapshots include authoritative labels and file identities. */
    public function resolve(Product $product, array $input, bool $claim = false): array
    {
        $fields = $product->personalization_fields ?? [];
        if (array_diff(array_keys($input), array_column($fields, 'key')) !== []) {
            throw ValidationException::withMessages(['personalization' => 'Product customization changed. Review the product before ordering.']);
        }
        $values = [];
        $snapshot = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? '';
            if (! is_string($value)) {
                throw ValidationException::withMessages(['personalization' => 'Enter valid customization values.']);
            }
            $value = trim($value);
            if ($value === '') {
                if ($field['required']) {
                    throw ValidationException::withMessages(['personalization.'.$key => 'Complete '.$field['label'].'.']);
                }

                continue;
            }
            $entry = ['key' => $key, 'type' => $field['type'], 'label' => $field['label'], 'labels' => $field['labels'] ?? []];
            if ($field['type'] === 'text') {
                if (mb_strlen($value) > $field['max_length'] || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
                    throw ValidationException::withMessages(['personalization.'.$key => 'Shorten or correct '.$field['label'].'.']);
                }
                $entry['value'] = $value;
            } else {
                $upload = ProductPersonalizationUpload::query()->where('store_id', $product->store_id)->where('product_id', $product->id)
                    ->where('field_key', $key)->where('token_hash', hash('sha256', $value))->where('expires_at', '>', now())
                    ->when($claim, fn ($query) => $query->lockForUpdate())->first();
                if (! $upload || ! Storage::disk('local')->exists($upload->path)) {
                    throw ValidationException::withMessages(['personalization.'.$key => 'Upload a new image for '.$field['label'].'.']);
                }
                if ($claim && ! $upload->claimed_at) {
                    $upload->update(['claimed_at' => now()]);
                }
                $entry['upload_id'] = $upload->id;
                $entry['filename'] = $upload->filename;
            }
            $values[$key] = $value;
            $snapshot[] = $entry;
        }
        ksort($values);

        return ['values' => $values, 'key' => $values === [] ? '' : hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot];
    }

    /** Only call after the enclosing order/cart has been authorized. */
    public static function present(array $snapshot, int $storeId): array
    {
        if ($snapshot === []) {
            return [];
        }
        $uploads = ProductPersonalizationUpload::query()->where('store_id', $storeId)->whereIn('id', array_column($snapshot, 'upload_id'))->get()->keyBy('id');

        return array_map(function ($entry) use ($uploads) {
            $entry['label'] = $entry['labels'][app()->getLocale()] ?? $entry['label'];
            unset($entry['labels']);
            if (isset($entry['upload_id'])) {
                $entry['url'] = $uploads->get($entry['upload_id'])?->downloadUrl();
            }

            return $entry;
        }, $snapshot);
    }
}
