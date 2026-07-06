<?php

namespace Tests\Unit\Services;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Services\RoomAvailabilityRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.2 Milestone 4 (ADR-88): CLEANING joins OUT_OF_ORDER/OUT_OF_SERVICE as unavailable
 * for room-assignment purposes. VACANT_DIRTY/VACANT_CLEAN/INSPECTED remain available.
 */
class RoomAvailabilityRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoomAvailabilityRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomAvailabilityRuleService();
    }

    public function test_cleaning_room_is_unavailable(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Cleaning]);

        $this->assertTrue($this->service->isRoomUnavailable($room));
    }

    public function test_out_of_order_room_is_unavailable(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfOrder]);

        $this->assertTrue($this->service->isRoomUnavailable($room));
    }

    public function test_out_of_service_room_is_unavailable(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::OutOfService]);

        $this->assertTrue($this->service->isRoomUnavailable($room));
    }

    public function test_vacant_clean_room_is_available(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantClean]);

        $this->assertFalse($this->service->isRoomUnavailable($room));
    }

    public function test_vacant_dirty_room_is_available_for_assignment_purposes(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::VacantDirty]);

        $this->assertFalse($this->service->isRoomUnavailable($room));
    }

    public function test_inspected_room_is_available(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Inspected]);

        $this->assertFalse($this->service->isRoomUnavailable($room));
    }

    public function test_occupied_room_is_not_unavailable_by_status_alone(): void
    {
        // OCCUPIED blocking is driven by RoomAssignment overlap (booking-based), not room.status —
        // isRoomUnavailable() must not treat OCCUPIED as a status-based block.
        $room = Room::factory()->create(['status' => RoomStatus::Occupied]);

        $this->assertFalse($this->service->isRoomUnavailable($room));
    }

    public function test_reserved_room_is_not_unavailable_by_status_alone(): void
    {
        $room = Room::factory()->create(['status' => RoomStatus::Reserved]);

        $this->assertFalse($this->service->isRoomUnavailable($room));
    }
}
