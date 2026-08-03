<?php

namespace App\Policies;

use App\Models\ServicePackage;
use App\Models\User;

class ServicePackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('service_packages.manage');
    }

    public function view(User $user, ServicePackage $package): bool
    {
        return $user->can('service_packages.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('service_packages.manage');
    }

    public function update(User $user, ServicePackage $package): bool
    {
        return $user->can('service_packages.manage');
    }

    public function toggle(User $user, ServicePackage $package): bool
    {
        return $user->can('service_packages.manage');
    }

    public function manageRates(User $user, ServicePackage $package): bool
    {
        return $user->can('service_packages.manage');
    }

    public function delete(User $user, ServicePackage $package): bool
    {
        return false; // no hard delete — deactivate via toggle instead
    }
}
