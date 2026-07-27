<?php

namespace App\Enums;

enum RoomStatus: string
{
    case VacantClean = 'VACANT_CLEAN';
    case VacantDirty = 'VACANT_DIRTY';
    case Occupied = 'OCCUPIED';
    case Reserved = 'RESERVED';
    case OutOfOrder = 'OUT_OF_ORDER';
    case OutOfService = 'OUT_OF_SERVICE';
    case Cleaning = 'CLEANING';
    case Inspected = 'INSPECTED';

    public function label(): string
    {
        return match ($this) {
            self::VacantClean => 'Trống sạch',
            self::VacantDirty => 'Trống bẩn',
            self::Occupied => 'Đang ở',
            self::Reserved => 'Đã đặt',
            self::OutOfOrder => 'Hỏng',
            self::OutOfService => 'Ngừng phục vụ',
            self::Cleaning => 'Đang dọn',
            self::Inspected => 'Đã kiểm tra',
        };
    }

    /**
     * Room Operations Simplification: the operational-state label shown on the
     * Housekeeping board, independent of the SẠCH/BẨN cleaning badge. VacantClean/
     * VacantDirty/Cleaning/Inspected are all "Trống" operationally — cleanliness
     * is read from CleaningStatus, not from this field, going forward.
     */
    public function operationalLabel(): string
    {
        return match ($this) {
            self::Occupied => 'Có khách',
            self::Reserved => 'Đã đặt',
            self::OutOfOrder => 'Bảo trì',
            self::OutOfService => 'Ngừng sử dụng',
            default => 'Trống',
        };
    }

    /**
     * Room Operations Simplification — Final Consistency Review: single, centralized
     * mapping from legacy RoomStatus to the implied CleaningStatus. This is the ONE
     * place that decides "what does this operational status imply about cleanliness"
     * — used by Room::normalizedCleaningStatus() (read-time fallback for rows with a
     * null cleaning_status), by HousekeepingService's legacy transition methods
     * (startCleaning/completeCleaning/passInspection/failInspection/skipInspection/
     * releaseFromOutOfOrder) to keep cleaning_status in sync on write, and by
     * RoomService (the Rooms admin CRUD form) for the same reason. No other file
     * should hand-roll this mapping.
     *
     * Rationale per case (Final Consistency Review):
     * - VacantDirty, Cleaning: unambiguously dirty — a room mid-clean is not yet clean.
     * - VacantClean, Inspected: unambiguously clean — cleaning is physically done;
     *   Inspected means "awaiting QC", not "still dirty".
     * - Occupied: no negative signal exists and check-in in this system has always
     *   implied a prior clean room in practice — Clean is the realistic operational
     *   baseline, not an arbitrary default.
     * - Reserved: this value is never actually written by any current write path in
     *   the app (confirmed by audit — RESERVED availability is derived from
     *   RoomAssignment, not Room.status) — Clean is a safe placeholder for a status
     *   that does not occur in live data.
     * - OutOfOrder, OutOfService: deliberately Dirty, NOT Clean — a room pulled into
     *   maintenance must never be silently presented as guest-ready. Whoever releases
     *   it from maintenance sees BẨN and must explicitly confirm SẠCH before it's
     *   offered again. This is the opposite of the naive "lock ⇒ irrelevant ⇒ Clean"
     *   assumption, by design.
     */
    public function impliedCleaningStatus(): CleaningStatus
    {
        return match ($this) {
            self::VacantDirty, self::Cleaning, self::OutOfOrder, self::OutOfService => CleaningStatus::Dirty,
            self::VacantClean, self::Inspected, self::Occupied, self::Reserved => CleaningStatus::Clean,
        };
    }

    public static function availableValues(): array
    {
        return [
            self::VacantClean->value,
            self::Inspected->value,
        ];
    }

    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
