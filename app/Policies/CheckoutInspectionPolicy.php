<?php

namespace App\Policies;

use App\Models\CheckoutInspection;
use App\Models\User;

class CheckoutInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('checkout_inspection.view');
    }

    public function view(User $user, CheckoutInspection $inspection): bool
    {
        return $user->can('checkout_inspection.view');
    }

    public function create(User $user): bool
    {
        return $user->can('checkout_inspection.perform');
    }

    public function update(User $user, CheckoutInspection $inspection): bool
    {
        return $user->can('checkout_inspection.perform');
    }

    public function override(User $user): bool
    {
        return $user->can('checkout_inspection.override');
    }
}
