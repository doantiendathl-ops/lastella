<?php

namespace App\Enums;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Đã tạo',
            self::Updated => 'Đã cập nhật',
            self::Deleted => 'Đã xóa',
            self::Restored => 'Đã khôi phục',
        };
    }
}
