<?php

namespace App\Enums;

enum PaymentType: string
{
    case Deposit = 'DEPOSIT';
    case AdditionalDeposit = 'ADDITIONAL_DEPOSIT';
    case RoomPayment = 'ROOM_PAYMENT';
    case ServicePayment = 'SERVICE_PAYMENT';
    case Refund = 'REFUND';
    case Adjustment = 'ADJUSTMENT';
}
