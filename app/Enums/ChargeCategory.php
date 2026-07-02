<?php

namespace App\Enums;

/**
 * UI grouping layer for charge types (ADR-71).
 * Separate from ChargeType (accounting) and ServiceRate (catalog items).
 */
class ChargeCategory
{
    public const ROOM_CHARGES = [
        ChargeType::Room,
        ChargeType::ExtraBed,
        ChargeType::ExtraPerson,
    ];

    public const FOOD_BEVERAGE = [
        ChargeType::FoodBeverage,
        ChargeType::Minibar,
    ];

    public const SERVICES = [
        ChargeType::Spa,
        ChargeType::Laundry,
        ChargeType::Transport,
        ChargeType::AirportTransfer,
    ];

    public const FEES = [
        ChargeType::LateCheckout,
        ChargeType::EarlyCheckin,
    ];

    public const OTHER = [
        ChargeType::Damage,
        ChargeType::Other,
    ];

    /** Returns all categories with their label and member charge types. */
    public static function groups(): array
    {
        return [
            ['label' => 'Phí phòng',       'types' => self::ROOM_CHARGES],
            ['label' => 'Ăn uống',         'types' => self::FOOD_BEVERAGE],
            ['label' => 'Dịch vụ',         'types' => self::SERVICES],
            ['label' => 'Phí phát sinh',   'types' => self::FEES],
            ['label' => 'Khác',            'types' => self::OTHER],
        ];
    }
}
