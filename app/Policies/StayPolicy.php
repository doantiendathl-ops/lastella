<?php

namespace App\Policies;

use App\Models\Stay;
use App\Models\User;

class StayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('report.view') || $user->can('stay.checkin') || $user->can('stay.checkout');
    }

    public function view(User $user, Stay $stay): bool
    {
        return $this->viewAny($user);
    }

    public function checkIn(User $user, Stay $stay): bool
    {
        return $user->can('stay.checkin');
    }

    public function checkOut(User $user, Stay $stay): bool
    {
        return $user->can('stay.checkout');
    }

    public function extend(User $user, Stay $stay): bool
    {
        return $user->can('stay.extend');
    }

    public function moveRoom(User $user, Stay $stay): bool
    {
        return $user->can('stay.room_move');
    }
}
