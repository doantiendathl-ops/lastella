<?php

namespace App\Enums;

enum RequestCategory: string
{
    case BedConfig     = 'bed_config';
    case ExtraItem     = 'extra_item';
    case Decoration    = 'decoration';
    case Accessibility = 'accessibility';
    case General       = 'general';

    public function label(): string
    {
        return match ($this) {
            self::BedConfig     => 'Cấu hình giường',
            self::ExtraItem     => 'Thêm đồ dùng',
            self::Decoration    => 'Trang trí',
            self::Accessibility => 'Hỗ trợ đặc biệt',
            self::General       => 'Yêu cầu khác',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
