<?php

namespace App\Enums;

enum StayEventType: string
{
    case CheckIn = 'CHECK_IN';
    case ExtendStay = 'EXTEND_STAY';
    case PartialCheckout = 'PARTIAL_CHECKOUT';
    case Checkout = 'CHECKOUT';
    case RoomMove = 'ROOM_MOVE';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Nhận phòng',
            self::ExtendStay => 'Gia hạn lưu trú',
            self::PartialCheckout => 'Trả phòng một phần',
            self::Checkout => 'Trả phòng',
            self::RoomMove => 'Đổi phòng',
        };
    }
}
