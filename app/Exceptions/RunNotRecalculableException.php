<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Night Audit pending-confirmation window (Phần 2): thrown when "Tính lại" is
 * requested for a run that is not eligible — either it never finished a full
 * pass (not COMPLETED), or its correction window has already closed
 * (already confirmed, by NightAuditService::confirmPendingRuns()).
 */
class RunNotRecalculableException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->withErrors(['run' => $this->getMessage()]);
    }
}
