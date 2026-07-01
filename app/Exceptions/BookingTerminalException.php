<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * ADR-44: Single terminal-booking guard for all service-layer mutations.
 * Thrown when a write operation (payment, folio charge, folio reopen) is
 * attempted on a booking in a terminal status (CheckedOut, Cancelled, NoShow).
 * Supersedes CannotDeletePaymentOnTerminalBookingException (Phase 3.1A stub).
 */
class BookingTerminalException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Booking đã kết thúc, không thể thực hiện thao tác này.');
    }

    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
