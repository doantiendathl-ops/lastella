<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }

    public function manageRates(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }

    public function toggle(User $user, Service $service): bool
    {
        return $user->can('services.manage');
    }
}
