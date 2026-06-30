<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a payment deletion is attempted on a terminal booking.
 * Phase 3.1B stub — enforced in BookingPaymentService::deletePayment().
 * ADR-23: payments on terminal bookings (CheckedOut, Cancelled, NoShow)
 * must not be deleted as they form part of the audit trail.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class CannotDeletePaymentOnTerminalBookingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Không thể xóa thanh toán của đặt phòng đã kết thúc.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['payment' => $this->getMessage()]);
    }
}
