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

    /**
     * Editing an already-recorded actual timestamp (backdate/correct) is an
     * ADMIN-only capability, distinct from the ordinary stay.checkin/
     * stay.checkout permission that RECEPTION also holds — see
     * docs/reports/early-checkin-admin-actual-time-override-implementation-report.md.
     */
    public function updateActualCheckIn(User $user, Stay $stay): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function updateActualCheckOut(User $user, Stay $stay): bool
    {
        return $user->hasRole('ADMIN');
    }
}
