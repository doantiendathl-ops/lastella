<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when a requirement update is attempted after the aggregate system
 * room charge has been posted to the folio.
 * ADR-4: once the system room charge exists, requirements must not change
 * because the folio total is authoritative and the estimate is superseded.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class RequirementLockedAfterRoomChargeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Không thể thay đổi yêu cầu phòng sau khi đã ghi nhận phí phòng vào folio.'
        );
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['requirement' => $this->getMessage()]);
    }
}
