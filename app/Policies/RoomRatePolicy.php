<?php

namespace App\Policies;

use App\Models\RoomRate;
use App\Models\User;

class RoomRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rates.manage');
    }

    public function view(User $user, RoomRate $roomRate): bool
    {
        return $user->can('rates.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('rates.manage');
    }

    public function update(User $user, RoomRate $roomRate): bool
    {
        return $user->can('rates.manage');
    }

    public function delete(User $user, RoomRate $roomRate): bool
    {
        return $user->can('rates.manage');
    }

    public function restore(User $user, RoomRate $roomRate): bool
    {
        return $user->can('rates.manage');
    }
}
