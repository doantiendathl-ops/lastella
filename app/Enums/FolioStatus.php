<?php

namespace App\Enums;

enum FolioStatus: string
{
    case Open   = 'OPEN';
    case Closed = 'CLOSED';
    case Voided = 'VOIDED';

    public function isEditable(): bool
    {
        return $this === self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open   => 'Đang mở',
            self::Closed => 'Đã đóng',
            self::Voided => 'Đã hủy',
        };
    }
}
