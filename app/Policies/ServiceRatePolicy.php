<?php

namespace App\Policies;

use App\Models\ServiceRate;
use App\Models\User;

class ServiceRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('service_rates.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('service_rates.manage');
    }

    public function update(User $user, ServiceRate $rate): bool
    {
        return $user->can('service_rates.manage');
    }

    public function delete(User $user, ServiceRate $rate): bool
    {
        return false; // no hard delete
    }

    public function toggleActive(User $user, ServiceRate $rate): bool
    {
        return $user->can('service_rates.manage');
    }
}
