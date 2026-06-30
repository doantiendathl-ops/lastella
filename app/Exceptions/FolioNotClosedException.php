<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when an operation requires a CLOSED folio (e.g. reopen) but the
 * folio is not currently Closed.
 * ADR-34: reopen is only valid from Closed state.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class FolioNotClosedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Folio không ở trạng thái đóng, không thể mở lại.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['folio' => $this->getMessage()]);
    }
}
