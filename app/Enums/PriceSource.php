<?php

namespace App\Enums;

enum PriceSource: string
{
    case RateTable = 'RATE_TABLE';
    case Manual = 'MANUAL';
    case SpecialDeal = 'SPECIAL_DEAL';
}
