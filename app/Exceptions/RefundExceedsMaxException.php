<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a refund amount would exceed the maximum refundable amount.
 * Phase 3.1B stub — enforced in BookingPaymentService::addRefund().
 * ADR-21: refund cap is min(paid_total, folio_total) to prevent over-refund.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class RefundExceedsMaxException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Số tiền hoàn trả vượt quá mức tối đa cho phép.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['amount' => $this->getMessage()]);
    }
}
