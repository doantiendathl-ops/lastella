<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when an operation that requires an OPEN folio is attempted on a
 * non-Open folio (Closed or Voided). Differs from FolioClosedException in
 * that it covers both Closed and Voided states as a single guard.
 * ADR-33 / ADR-37 shared state guard.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class FolioNotOpenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Folio không ở trạng thái mở, không thể thực hiện thao tác này.');
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['folio' => $this->getMessage()]);
    }
}
