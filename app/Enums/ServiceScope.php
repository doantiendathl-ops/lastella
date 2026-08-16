<?php

namespace App\Enums;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 7).
 * Where a Service applies. Never inferred from a service's code/name —
 * always read from this configured field.
 */
enum ServiceScope: string
{
    case Booking = 'BOOKING';
    case Room = 'ROOM';
    case Both = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::Booking => 'Toàn booking',
            self::Room => 'Theo phòng',
            self::Both => 'Cả hai (chọn khi tạo)',
        };
    }

    /** Whether a booking_services row for this scope must carry a room_assignment_id. */
    public function requiresRoom(): bool
    {
        return $this === self::Room;
    }

    /** Whether the staff-facing form must let them choose Booking vs Room. */
    public function isChoosableAtEnrollment(): bool
    {
        return $this === self::Both;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
