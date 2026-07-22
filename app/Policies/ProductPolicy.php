<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Services\Rbac\UserScope;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    private function owns(User $user, Product $product): bool
    {
        if (UserScope::isAdmin($user)) {
            return true;
        }

        return (int) ($product->store?->owner_user_id) === UserScope::effectiveMerchantUserId($user);
    }
}
