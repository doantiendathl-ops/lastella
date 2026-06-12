<?php

namespace App\Enums;

enum StayStatus: string
{
    case Reserved = 'RESERVED';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
}
