<?php

namespace App\Enums;

enum InspectionResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Đạt',
            self::Fail => 'Không đạt',
            self::Skip => 'Bỏ qua kiểm tra',
        };
    }

    public function roomOutcome(): RoomStatus
    {
        return match ($this) {
            self::Pass, self::Skip => RoomStatus::VacantClean,
            self::Fail             => RoomStatus::VacantDirty,
        };
    }
}
