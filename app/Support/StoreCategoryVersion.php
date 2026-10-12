<?php

namespace App\Support;

use App\Models\Category;

final class StoreCategoryVersion
{
    public static function for(Category $category): string
    {
        return hash('sha256', json_encode($category->only(['id', 'name', 'slug', 'description', 'image', 'parent_id', 'is_active', 'is_hidden', 'show_in_header', 'position', 'seo_title', 'seo_description']), JSON_THROW_ON_ERROR));
    }
}
