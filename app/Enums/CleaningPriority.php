<?php

namespace App\Enums;

enum CleaningPriority: string
{
    case Emergency = 'EMERGENCY';
    case High      = 'HIGH';
    case Normal    = 'NORMAL';
    case Low       = 'LOW';

    public function sortOrder(): int
    {
        return match ($this) {
            self::Emergency => 1,
            self::High      => 2,
            self::Normal    => 3,
            self::Low       => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Khẩn cấp',
            self::High      => 'Cao',
            self::Normal    => 'Thường',
            self::Low       => 'Thấp',
        };
    }
}
