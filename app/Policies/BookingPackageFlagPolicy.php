<?php

namespace App\Policies;

use App\Models\User;

class BookingPackageFlagPolicy
{
    public function enroll(User $user): bool
    {
        return $user->can('booking.package.manage');
    }

    public function unenroll(User $user): bool
    {
        return $user->can('booking.package.manage');
    }
}
