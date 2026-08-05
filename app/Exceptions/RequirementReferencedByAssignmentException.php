<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Thrown when deleting a BookingRequirement that has ever been referenced by
 * a RoomAssignment (booking_requirement_id), regardless of assignment status
 * (Assigned or Released).
 *
 * Room Demand/Room Board Unification M1 — Architecture Review REVISION 3,
 * Product Owner Decision #13/Mục V: hard-deleting such a requirement would
 * silently destroy assignment-to-demand traceability, including for rooms
 * already released. The database also enforces this via restrictOnDelete()
 * on room_assignments.booking_requirement_id; this exception is the
 * friendly, application-level guard that runs before that constraint would
 * otherwise surface as a raw SQL error.
 * Renders as a user-visible redirect error for Inertia compatibility.
 */
class RequirementReferencedByAssignmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Không thể xóa yêu cầu phòng đã từng được gán phòng tham chiếu (kể cả đã giải phóng).'
        );
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors(['requirement' => $this->getMessage()]);
    }
}
