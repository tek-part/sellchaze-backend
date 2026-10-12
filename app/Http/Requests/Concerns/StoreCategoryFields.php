<?php

namespace App\Http\Requests\Concerns;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Validation\Rule;

trait StoreCategoryFields
{
    protected function categoryFields(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('store_id', app(CurrentStore::class)->id())],
            'show_in_header' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'remove_image' => ['sometimes', 'boolean'],
            'edit_version' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
