<?php

namespace App\Actions\Categories;

use App\Models\Category;

class CreateCategoryAction
{
    /**
     * Create a category from validated data.
     *
     * @param  array<string, mixed>  $data
     * @return Category
     */
    public function __invoke(array $data): Category
    {
        return Category::create($data);
    }
}
