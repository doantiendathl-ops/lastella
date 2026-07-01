<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a write operation is attempted on a VOIDED folio.
 * ADR-37: VOIDED folio is terminal — no further mutations are allowed.
 * Renders as a user-visible redirect error so Inertia can display it.
 */
class FolioVoidedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Folio đã bị hủy bỏ, không thể thực hiện thao tác này.');
    }

    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
