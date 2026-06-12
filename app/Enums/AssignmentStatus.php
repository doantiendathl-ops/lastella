<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Assigned = 'ASSIGNED';
    case Released = 'RELEASED';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public static function activeValues(): array
    {
        return [
            self::Assigned->value,
            self::CheckedIn->value,
        ];
    }
}
