<?php

namespace App\Enums;

enum ChargeType: string
{
    case Room          = 'ROOM';
    case FoodBeverage  = 'FOOD_BEVERAGE';
    case Spa           = 'SPA';
    case Laundry       = 'LAUNDRY';
    case Minibar       = 'MINIBAR';
    case Damage        = 'DAMAGE';
    case LateCheckout  = 'LATE_CHECKOUT';
    case EarlyCheckin  = 'EARLY_CHECKIN';
    case Transport     = 'TRANSPORT';
    case Other         = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Room         => 'Tiền phòng',
            self::FoodBeverage => 'Ăn uống',
            self::Spa          => 'Spa',
            self::Laundry      => 'Giặt ủi',
            self::Minibar      => 'Minibar',
            self::Damage       => 'Bồi thường',
            self::LateCheckout => 'Trả phòng muộn',
            self::EarlyCheckin => 'Nhận phòng sớm',
            self::Transport    => 'Vận chuyển',
            self::Other        => 'Khác',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
