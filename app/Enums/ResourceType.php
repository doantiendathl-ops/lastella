<?php

namespace App\Enums;

enum ResourceType: string
{
    case Room = 'room';
    case Hall = 'hall';
    case MeetingRoom = 'meeting_room';
    case Restaurant = 'restaurant';
    case Pool = 'pool';
    case Sauna = 'sauna';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Phòng',
            self::Hall => 'Hội trường',
            self::MeetingRoom => 'Phòng họp',
            self::Restaurant => 'Nhà hàng',
            self::Pool => 'Hồ bơi',
            self::Sauna => 'Phòng xông hơi',
            self::Other => 'Khác',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
