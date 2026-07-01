<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * ADR-50: Thrown when a void is attempted on a system-posted folio entry (posting_key != null).
 * System entries are created automatically and cannot be voided by anyone.
 * This is a domain invariant, not an authorization rule — enforced in FolioService::voidEntry().
 */
class SystemEntryVoidException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Không thể huỷ mục phí hệ thống. Mục này được tạo tự động và không thể xoá.');
    }

    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
