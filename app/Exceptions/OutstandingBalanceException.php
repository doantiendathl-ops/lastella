<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * ADR-40: Thrown when checkout is attempted with an outstanding balance.
 * The entire checkout transaction is rolled back — no partial state changes occur.
 */
class OutstandingBalanceException extends RuntimeException
{
    public function __construct(private readonly float $balanceDue)
    {
        parent::__construct(
            'Không thể trả phòng khi còn số dư chưa thanh toán: '
            . number_format($balanceDue, 0, ',', '.') . ' đ.'
        );
    }

    public function getBalanceDue(): float
    {
        return $this->balanceDue;
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['checkout' => $this->getMessage()]);
    }
}
