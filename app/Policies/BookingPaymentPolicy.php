<?php

namespace App\Policies;

use App\Models\BookingPayment;
use App\Models\User;

class BookingPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('report.view') || $user->can('payment.create');
    }

    public function view(User $user, BookingPayment $bookingPayment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('payment.create');
    }
}
