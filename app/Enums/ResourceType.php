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
            self::Room => 'Room',
            self::Hall => 'Hall',
            self::MeetingRoom => 'Meeting Room',
            self::Restaurant => 'Restaurant',
            self::Pool => 'Pool',
            self::Sauna => 'Sauna',
            self::Other => 'Other',
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
