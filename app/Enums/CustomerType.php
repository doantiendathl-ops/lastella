<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'INDIVIDUAL';
    case Group = 'GROUP';
    case Company = 'COMPANY';
    case Tour = 'TOUR';
    case WalkIn = 'WALK_IN';
}
