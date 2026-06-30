<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a void is attempted on an entry that is already voided.
 * ADR-33: void is an idempotency guard — re-voiding must be rejected.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class AlreadyVoidedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mục phí này đã được hủy bỏ trước đó.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['entry' => $this->getMessage()]);
    }
}
