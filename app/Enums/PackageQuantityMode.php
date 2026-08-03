<?php

namespace App\Enums;

/**
 * Registry of how a package's quantity is determined. Only entries returned
 * by implemented() are backed by real enrollment/posting logic today.
 */
enum PackageQuantityMode: string
{
    case None = 'NONE';
    case ManualInput = 'MANUAL_INPUT';
    case FromAdults = 'FROM_ADULTS';
    case FromTotalGuests = 'FROM_TOTAL_GUESTS';
    case FromRooms = 'FROM_ROOMS';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Không nhập số lượng (luôn cố định)',
            self::ManualInput => 'Nhập số lượng khi đăng ký',
            self::FromAdults => 'Lấy từ số người lớn',
            self::FromTotalGuests => 'Lấy từ tổng số khách',
            self::FromRooms => 'Lấy từ số phòng',
        };
    }

    /** @return array<self> */
    public static function implemented(): array
    {
        return [self::None, self::ManualInput];
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
