<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Models\User;

class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return $user->can('services.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->can('services.manage');
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return $user->can('services.manage') && ! $category->services()->exists();
    }
}
