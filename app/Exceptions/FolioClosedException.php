<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a write operation is attempted on a CLOSED folio.
 * ADR-33: folio state must be Open for charge/void operations.
 * Shared foundation for Phase 3.1B charge posting and Phase 3.1A close guard.
 * Renders as a user-visible redirect error so Inertia can display it.
 */
class FolioClosedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Folio đã đóng, không thể thực hiện thao tác này.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['folio' => $this->getMessage()]);
    }
}
