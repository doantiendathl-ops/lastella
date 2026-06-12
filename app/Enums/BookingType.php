<?php

namespace App\Enums;

enum BookingType: string
{
    case Overnight = 'OVERNIGHT';
    case DayUse = 'DAY_USE';
    case Hourly = 'HOURLY';
    case EarlyCheckin = 'EARLY_CHECKIN';
    case LateCheckout = 'LATE_CHECKOUT';
}
