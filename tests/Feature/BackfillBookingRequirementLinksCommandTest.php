<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M1 — room-assignments:backfill-booking-requirements.
 */
class BackfillBookingRequirementLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoomType(): RoomType
    {
        return RoomType::factory()->create();
    }

    public function test_dry_run_reports_counts_without_writing_to_database(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements')
            ->assertSuccessful();

        $this->assertNull($assignment->fresh()->booking_requirement_id);
        $this->assertSame($requirement->quantity, $requirement->fresh()->quantity);
    }

    public function test_unique_match_is_reported_and_not_written_without_apply(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements')
            ->expectsOutputToContain('Unique match (would map): 1')
            ->assertSuccessful();
    }

    public function test_apply_maps_the_unique_match(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])
            ->expectsOutputToContain('Unique match (mapped): 1')
            ->assertSuccessful();

        $this->assertSame($requirement->id, $assignment->fresh()->booking_requirement_id);
    }

    public function test_running_apply_twice_does_not_duplicate_or_change_result(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])->assertSuccessful();
        $this->assertSame($requirement->id, $assignment->fresh()->booking_requirement_id);

        // Second run: the assignment is now excluded from the NULL query
        // entirely, so it must be reported as "already mapped", not re-matched.
        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])
            ->expectsOutputToContain('Already mapped (skipped, untouched): 1')
            ->expectsOutputToContain('NULL mapping found: 0')
            ->assertSuccessful();

        $this->assertSame($requirement->id, $assignment->fresh()->booking_requirement_id);
    }

    public function test_ambiguous_candidates_are_not_mapped(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        BookingRequirement::factory()->create(['booking_id' => $booking->id, 'room_type_id' => $roomType->id]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])
            ->expectsOutputToContain('Ambiguous (>=2 candidates, left NULL): 1')
            ->assertSuccessful();

        $this->assertNull($assignment->fresh()->booking_requirement_id);
    }

    public function test_no_matching_requirement_is_not_mapped(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        // No BookingRequirement at all for this booking/room_type — matches the
        // "chọn phòng trước khi có demand" gap described in the Architecture Review.
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])
            ->expectsOutputToContain('No matching requirement (left NULL): 1')
            ->assertSuccessful();

        $this->assertNull($assignment->fresh()->booking_requirement_id);
    }

    public function test_assignment_with_existing_mapping_is_left_untouched(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $originalRequirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        // A second requirement line exists too, so if the command mistakenly
        // re-evaluated this row it would see it as ambiguous — proving it was
        // truly skipped, not "coincidentally re-mapped to the same value".
        BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $originalRequirement->id,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])
            ->expectsOutputToContain('Already mapped (skipped, untouched): 1')
            ->expectsOutputToContain('NULL mapping found: 0')
            ->assertSuccessful();

        $this->assertSame($originalRequirement->id, $assignment->fresh()->booking_requirement_id);
    }

    public function test_backfill_never_changes_requirement_quantity_price_source_or_note(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => 3,
            'room_price' => 777000,
            'note' => 'original note',
        ]);
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])->assertSuccessful();

        $fresh = $requirement->fresh();
        $this->assertSame(3, $fresh->quantity);
        $this->assertEquals(777000, (float) $fresh->room_price);
        $this->assertSame('original note', $fresh->note);
    }

    public function test_backfill_never_creates_a_new_requirement(): void
    {
        $roomType = $this->makeRoomType();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create();
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => null,
        ]);

        $before = BookingRequirement::count();

        $this->artisan('room-assignments:backfill-booking-requirements', ['--apply' => true])->assertSuccessful();

        $this->assertSame($before, BookingRequirement::count());
    }
}
