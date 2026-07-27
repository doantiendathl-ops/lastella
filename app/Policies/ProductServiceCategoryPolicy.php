<?php

namespace App\Policies;

use App\Models\ProductServiceCategory;
use App\Models\User;

class ProductServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('product_services.manage');
    }

    public function view(User $user, ProductServiceCategory $category): bool
    {
        return $user->can('product_services.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('product_services.manage');
    }

    public function update(User $user, ProductServiceCategory $category): bool
    {
        return $user->can('product_services.manage');
    }

    public function delete(User $user, ProductServiceCategory $category): bool
    {
        return $user->can('product_services.manage') && ! $category->productServices()->exists();
    }
}
