<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\User;

class BookingSpecialRequestPolicy
{
    /**
     * Any user who holds at least one special_request.* permission may view requests.
     * ACCOUNTANT holds none → no view access (ADR-81).
     */
    public function view(User $user, BookingSpecialRequest $request): bool
    {
        return $user->can('special_request.create')
            || $user->can('special_request.fulfill')
            || $user->can('special_request.cancel');
    }

    /**
     * Create a request on a booking.
     * Roles: ADMIN, MANAGER, RECEPTION — all hold special_request.create.
     */
    public function create(User $user, Booking $booking): bool
    {
        return $user->can('special_request.create');
    }

    /**
     * Acknowledge or fulfill a request.
     * Single permission covers both transitions (ADR-81).
     * Roles: ADMIN, MANAGER, HOUSEKEEPING — all hold special_request.fulfill.
     */
    public function fulfill(User $user, BookingSpecialRequest $request): bool
    {
        return $user->can('special_request.fulfill');
    }

    /**
     * Cancel a request.
     * Roles: ADMIN, MANAGER — hold special_request.cancel.
     * RECEPTION, HOUSEKEEPING: no cancel permission.
     */
    public function cancel(User $user, BookingSpecialRequest $request): bool
    {
        return $user->can('special_request.cancel');
    }
}
