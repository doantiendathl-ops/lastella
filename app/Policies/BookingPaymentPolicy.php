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

    public function delete(User $user, BookingPayment $bookingPayment): bool
    {
        if ($user->hasRole('ADMIN')) {
            return true;
        }

        if ($user->can('payment.delete')) {
            return $bookingPayment->created_at->isToday();
        }

        return false;
    }
}
