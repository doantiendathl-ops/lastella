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
        // User request (2026-08-19 chat) — was hasRole('ADMIN'); the "any
        // date" bypass (payment.delete alone only allows deleting TODAY's
        // payment, see below) is now its own grantable permission.
        if ($user->can('payment.delete_any_date')) {
            return true;
        }

        if ($user->can('payment.delete')) {
            return $bookingPayment->created_at->isToday();
        }

        return false;
    }
}
