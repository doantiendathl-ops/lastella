<?php

namespace App\Enums;

/**
 * Registry of package pricing strategies. Only entries returned by
 * implemented() have real posting-job support and test coverage — those are
 * the only ones the admin UI (Milestone 2) may offer or accept.
 */
enum PackageCalculationStrategy: string
{
    case OncePerStayPerNight = 'ONCE_PER_STAY_PER_NIGHT';
    case ManualQuantityPerNight = 'MANUAL_QUANTITY_PER_NIGHT';
    case OncePerBooking = 'ONCE_PER_BOOKING';
    case OncePerRoom = 'ONCE_PER_ROOM';
    case PerRoomPerNight = 'PER_ROOM_PER_NIGHT';
    case PerAdultPerNight = 'PER_ADULT_PER_NIGHT';
    case ManualQuantityOnce = 'MANUAL_QUANTITY_ONCE';

    public function label(): string
    {
        return match ($this) {
            self::OncePerStayPerNight => 'Một lần mỗi đêm (cố định)',
            self::ManualQuantityPerNight => 'Số lượng nhập tay, mỗi đêm',
            self::OncePerBooking => 'Một lần cho booking',
            self::OncePerRoom => 'Một lần cho mỗi phòng',
            self::PerRoomPerNight => 'Mỗi phòng mỗi đêm',
            self::PerAdultPerNight => 'Mỗi người lớn mỗi đêm',
            self::ManualQuantityOnce => 'Số lượng nhập tay, tính một lần',
        };
    }

    /** @return array<self> Strategies with real posting-job support today. */
    public static function implemented(): array
    {
        return [self::OncePerStayPerNight, self::ManualQuantityPerNight];
    }

    public function isImplemented(): bool
    {
        return in_array($this, self::implemented(), true);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label(), 'implemented' => $case->isImplemented()],
            self::cases(),
        );
    }
}
