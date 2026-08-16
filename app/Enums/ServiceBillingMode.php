<?php

namespace App\Enums;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 8).
 * How a Service is charged. BOTH means the staff member chooses
 * ONE_TIME or PER_NIGHT at creation time — the choice is snapshotted onto
 * booking_services.billing_mode_selected and never re-derived.
 */
enum ServiceBillingMode: string
{
    case OneTime = 'ONE_TIME';
    case PerNight = 'PER_NIGHT';
    case Both = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'Một lần',
            self::PerNight => 'Qua đêm',
            self::Both => 'Cả hai (chọn khi tạo)',
        };
    }

    public function isChoosableAtEnrollment(): bool
    {
        return $this === self::Both;
    }

    /** The concrete mode(s) a booking_services row may snapshot for this Service.billing_mode. */
    public function allowedSelections(): array
    {
        return match ($this) {
            self::OneTime => [self::OneTime],
            self::PerNight => [self::PerNight],
            self::Both => [self::OneTime, self::PerNight],
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
