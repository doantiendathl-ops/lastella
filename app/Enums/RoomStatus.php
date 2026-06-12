<?php

namespace App\Enums;

enum RoomStatus: string
{
    case VacantClean = 'VACANT_CLEAN';
    case VacantDirty = 'VACANT_DIRTY';
    case Occupied = 'OCCUPIED';
    case Reserved = 'RESERVED';
    case OutOfOrder = 'OUT_OF_ORDER';
    case OutOfService = 'OUT_OF_SERVICE';
    case Cleaning = 'CLEANING';
    case Inspected = 'INSPECTED';

    public function label(): string
    {
        return match ($this) {
            self::VacantClean => 'Vacant Clean',
            self::VacantDirty => 'Vacant Dirty',
            self::Occupied => 'Occupied',
            self::Reserved => 'Reserved',
            self::OutOfOrder => 'Out Of Order',
            self::OutOfService => 'Out Of Service',
            self::Cleaning => 'Cleaning',
            self::Inspected => 'Inspected',
        };
    }

    public static function availableValues(): array
    {
        return [
            self::VacantClean->value,
            self::Inspected->value,
        ];
    }

    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
