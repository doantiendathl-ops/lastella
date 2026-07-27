<?php

namespace App\Policies;

use App\Models\ProductService;
use App\Models\User;

class ProductServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('product_services.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('product_services.manage');
    }

    public function update(User $user, ProductService $productService): bool
    {
        return $user->can('product_services.manage');
    }

    public function delete(User $user, ProductService $productService): bool
    {
        return false; // no hard delete — deactivate instead
    }

    public function toggleActive(User $user, ProductService $productService): bool
    {
        return $user->can('product_services.manage');
    }
}
