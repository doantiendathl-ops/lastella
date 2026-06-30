<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a negative adjustment would drive the paid total below zero.
 * Phase 3.1B stub — enforced in BookingPaymentService::addAdjustment().
 * ADR-22: adjustments must not create a negative paid balance.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class NegativeAdjustmentExceedsPaidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Điều chỉnh âm vượt quá tổng số tiền đã thanh toán.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['amount' => $this->getMessage()]);
    }
}
