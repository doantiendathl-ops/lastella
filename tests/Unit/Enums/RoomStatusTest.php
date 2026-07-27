<?php

namespace Tests\Unit\Enums;

use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Final Consistency Review: exhaustive coverage of the single centralized
 * RoomStatus → CleaningStatus mapping used by Room::normalizedCleaningStatus(),
 * HousekeepingService's legacy transitions, RoomService's CRUD sync, and the
 * cleaning_status backfill migration (which mirrors this mapping literally).
 */
class RoomStatusTest extends TestCase
{
    public static function mappingProvider(): array
    {
        return [
            'VACANT_DIRTY is Dirty' => [RoomStatus::VacantDirty, CleaningStatus::Dirty],
            'CLEANING is Dirty' => [RoomStatus::Cleaning, CleaningStatus::Dirty],
            'OUT_OF_ORDER is Dirty (never presumed guest-ready while locked)' => [RoomStatus::OutOfOrder, CleaningStatus::Dirty],
            'OUT_OF_SERVICE is Dirty (never presumed guest-ready while locked)' => [RoomStatus::OutOfService, CleaningStatus::Dirty],
            'VACANT_CLEAN is Clean' => [RoomStatus::VacantClean, CleaningStatus::Clean],
            'INSPECTED is Clean' => [RoomStatus::Inspected, CleaningStatus::Clean],
            'OCCUPIED is Clean (realistic operational baseline)' => [RoomStatus::Occupied, CleaningStatus::Clean],
            'RESERVED is Clean (unused status in live write paths)' => [RoomStatus::Reserved, CleaningStatus::Clean],
        ];
    }

    #[DataProvider('mappingProvider')]
    public function test_implied_cleaning_status_mapping(RoomStatus $status, CleaningStatus $expected): void
    {
        $this->assertSame($expected, $status->impliedCleaningStatus());
    }

    public function test_every_room_status_case_has_an_explicit_mapping(): void
    {
        // No wildcard `default` arm in impliedCleaningStatus() — this guards against
        // a future RoomStatus case silently falling through unmapped.
        foreach (RoomStatus::cases() as $status) {
            $this->assertInstanceOf(CleaningStatus::class, $status->impliedCleaningStatus());
        }

        $this->assertCount(8, RoomStatus::cases(), 'A new RoomStatus case was added — extend mappingProvider() above too.');
    }
}
