<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Draft = 'DRAFT';
    case PendingAssignment = 'PENDING_ASSIGNMENT';
    case PartiallyAssigned = 'PARTIALLY_ASSIGNED';
    case FullyAssigned = 'FULLY_ASSIGNED';
    case Held = 'HELD';
    case Deposited = 'DEPOSITED';
    case PartiallyCheckedIn = 'PARTIALLY_CHECKED_IN';
    case CheckedIn = 'CHECKED_IN';
    case PartiallyCheckedOut = 'PARTIALLY_CHECKED_OUT';
    case CheckedOut = 'CHECKED_OUT';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::NoShow, self::CheckedOut], true);
    }
}
