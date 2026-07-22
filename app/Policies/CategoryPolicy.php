<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Services\Rbac\UserScope;

class CategoryPolicy
{
    public function view(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    private function owns(User $user, Category $category): bool
    {
        if (UserScope::isAdmin($user)) {
            return true;
        }

        return (int) ($category->store?->owner_user_id) === UserScope::effectiveMerchantUserId($user);
    }
}
