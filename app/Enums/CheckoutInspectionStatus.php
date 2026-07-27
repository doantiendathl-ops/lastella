<?php

namespace App\Enums;

enum CheckoutInspectionStatus: string
{
    case Draft = 'DRAFT';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Đang kiểm',
            self::Completed => 'Đã kiểm',
        };
    }
}
