<?php

namespace App\Enums;

enum HousekeepingAssignmentStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Cancelled => true,
            default                     => false,
        };
    }

    public static function activeValues(): array
    {
        return [self::Pending->value, self::InProgress->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Chờ thực hiện',
            self::InProgress => 'Đang dọn',
            self::Done       => 'Đã xong',
            self::Cancelled  => 'Đã hủy',
        };
    }
}
