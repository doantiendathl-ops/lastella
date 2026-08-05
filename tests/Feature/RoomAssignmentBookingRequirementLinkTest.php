<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M1 — migration and model relationship
 * tests for room_assignments.booking_requirement_id.
 */
class RoomAssignmentBookingRequirementLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_requirement_id_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('room_assignments', 'booking_requirement_id'));
    }

    public function test_booking_requirement_id_is_nullable(): void
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->assertNull($assignment->fresh()->booking_requirement_id);
    }

    public function test_relationship_resolves_the_linked_requirement(): void
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
        ]);

        $this->assertTrue($assignment->bookingRequirement->is($requirement));
        $this->assertTrue($requirement->roomAssignments->contains($assignment));
    }

    public function test_unmapped_assignment_still_reads_fine_with_null_relationship(): void
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();

        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $fresh = RoomAssignment::with('bookingRequirement')->findOrFail($assignment->id);

        $this->assertNull($fresh->bookingRequirement);
        $this->assertSame($assignment->id, $fresh->id);
    }

    public function test_foreign_key_blocks_deleting_a_referenced_requirement_at_the_database_level(): void
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
        ]);

        // Bypasses the application-level guard on purpose to prove the
        // restrictOnDelete() foreign key itself is the backstop.
        $this->expectException(QueryException::class);

        $requirement->delete();
    }

    public function test_migration_rollback_runs_cleanly_and_restores_state(): void
    {
        $this->assertTrue(Schema::hasColumn('room_assignments', 'booking_requirement_id'));

        $migration = require database_path('migrations/2026_08_05_000000_add_booking_requirement_id_to_room_assignments_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('room_assignments', 'booking_requirement_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('room_assignments', 'booking_requirement_id'));
    }
}
