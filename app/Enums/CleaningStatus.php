<?php

namespace App\Enums;

/**
 * Room Operations Simplification: the single source of truth for the "SẠCH/BẨN"
 * badge shown on the Housekeeping board, independent of RoomStatus (which still
 * conflates operational state — vacant/occupied/maintenance — with cleanliness
 * for legacy VACANT_CLEAN/VACANT_DIRTY/CLEANING/INSPECTED values).
 */
enum CleaningStatus: string
{
    case Clean = 'CLEAN';
    case Dirty = 'DIRTY';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Sạch',
            self::Dirty => 'Bẩn',
        };
    }
}
