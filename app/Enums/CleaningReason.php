<?php

namespace App\Enums;

enum CleaningReason: string
{
    case Checkout       = 'CHECKOUT';
    case Stayover       = 'STAYOVER';
    case Vip            = 'VIP';
    case Maintenance    = 'MAINTENANCE';
    case DeepCleaning   = 'DEEP_CLEANING';
    case EarlyCheckin   = 'EARLY_CHECKIN';
    case SpecialRequest = 'SPECIAL_REQUEST';
    case Manual          = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Checkout       => 'Trả phòng',
            self::Stayover       => 'Dọn trong kỳ lưu trú',
            self::Vip            => 'VIP',
            self::Maintenance    => 'Sau bảo trì',
            self::DeepCleaning   => 'Vệ sinh sâu',
            self::EarlyCheckin   => 'Nhận phòng sớm',
            self::SpecialRequest => 'Yêu cầu đặc biệt',
            self::Manual         => 'Thao tác thủ công',
        };
    }
}
