<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('report.view')
            || $user->can('booking.create')
            || $user->can('booking.update')
            || $user->can('booking.cancel');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('booking.create');
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->can('booking.update');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->can('booking.cancel');
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->can('booking.cancel');
    }
}
