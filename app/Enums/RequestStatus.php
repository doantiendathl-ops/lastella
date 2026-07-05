<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Pending      = 'pending';
    case Acknowledged = 'acknowledged';
    case Fulfilled    = 'fulfilled';
    case Cancelled    = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fulfilled, self::Cancelled => true,
            default                          => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending      => in_array($next, [self::Acknowledged, self::Cancelled]),
            self::Acknowledged => in_array($next, [self::Fulfilled, self::Cancelled]),
            default            => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending      => 'Chờ xử lý',
            self::Acknowledged => 'Đã tiếp nhận',
            self::Fulfilled    => 'Đã hoàn thành',
            self::Cancelled    => 'Đã hủy',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
