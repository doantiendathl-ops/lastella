<?php

namespace App\Enums;

enum StayEventType: string
{
    case CheckIn = 'CHECK_IN';
    case ExtendStay = 'EXTEND_STAY';
    case PartialCheckout = 'PARTIAL_CHECKOUT';
    case Checkout = 'CHECKOUT';
    case RoomMove = 'ROOM_MOVE';
    case InspectionCompleted = 'INSPECTION_COMPLETED';
    case InspectionEdited = 'INSPECTION_EDITED';
    case InspectionSkipped = 'INSPECTION_SKIPPED';
    case CheckInTimeAdjusted = 'CHECK_IN_TIME_ADJUSTED';
    case CheckOutTimeAdjusted = 'CHECK_OUT_TIME_ADJUSTED';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Nhận phòng',
            self::ExtendStay => 'Gia hạn lưu trú',
            self::PartialCheckout => 'Trả phòng một phần',
            self::Checkout => 'Trả phòng',
            self::RoomMove => 'Đổi phòng',
            self::InspectionCompleted => 'Hoàn tất kiểm đồ',
            self::InspectionEdited => 'Chỉnh sửa phiếu kiểm đồ',
            self::InspectionSkipped => 'Bỏ qua kiểm đồ',
            self::CheckInTimeAdjusted => 'Điều chỉnh thời gian nhận phòng thực tế',
            self::CheckOutTimeAdjusted => 'Điều chỉnh thời gian trả phòng thực tế',
        };
    }
}
